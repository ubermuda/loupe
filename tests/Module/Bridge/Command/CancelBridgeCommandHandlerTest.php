<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\CancelBridgeCommandCommand;
use App\Module\Bridge\Command\CancelBridgeCommandHandler;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\CardHoldRepository;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\RecordingAuditor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Ubermuda\AuditBundle\AuditOutcome;

final class CancelBridgeCommandHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-09-29T12:03:00+00:00';

    public function test_a_pending_command_is_cancelled_and_audited_without_the_reason(): void
    {
        $this->boot();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        [$owner, $run] = $this->scenario('cancel-pending');
        $pending = $this->seedCommand($this->em(), $run, kind: BridgeCommandKind::ResumeRun, requestedBy: $owner);
        $pending->reason = 'a reason a person wrote';
        $this->em()->flush();

        $cancelled = $this->cancel($run, $owner);

        self::assertSame((string) $pending->id, (string) $cancelled->id);
        $this->em()->clear();
        $stored = $this->em()->find(BridgeCommand::class, $pending->id);
        self::assertInstanceOf(BridgeCommand::class, $stored);
        self::assertSame(BridgeCommandState::Cancelled, $stored->state);
        self::assertSame(self::NOW, $stored->settledAt?->format(\DateTimeInterface::ATOM));

        $record = $audit->record('bridge.command_cancelled');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame((string) $pending->id, $record->subject?->id);
        self::assertSame([
            'commandId' => (string) $pending->id,
            'kind' => 'resume-run',
            'projectId' => (string) $run->project->id,
            'runId' => (string) $run->id,
            'bridgeId' => (string) $run->bridgeId,
        ], $record->context);
    }

    public function test_a_run_with_no_pending_command_is_refused(): void
    {
        $this->boot();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        [$owner, $run] = $this->scenario('cancel-nothing');
        $this->seedCommand($this->em(), $run, state: BridgeCommandState::Done);

        try {
            $this->cancel($run, $owner);
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['run' => 'bridge.command.error.nothing_pending'], $e->errors);
        }
        self::assertSame([], $audit->records('bridge.command_cancelled'));
    }

    /** The bulk expiry sweep can settle the command after the page read it. */
    public function test_a_command_the_sweep_expired_is_refused(): void
    {
        $this->boot();
        [$owner, $run] = $this->scenario('cancel-expired');
        $command = $this->seedCommand($this->em(), $run);
        $this->em()->getConnection()->executeStatement("UPDATE bridge_commands SET state = 'expired' WHERE id = ?", [(string) $command->id]);

        try {
            $this->cancel($run, $owner);
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['run' => 'bridge.command.error.nothing_pending'], $e->errors);
        }
    }

    public function test_a_cancelled_stop_releases_the_hold_of_its_run(): void
    {
        $this->boot();
        [$owner, $run] = $this->scenario('cancel-stop-release');
        $this->seedCommand($this->em(), $run);
        $this->holds()->hold($run->project, $run->cardId, $run, $owner);

        $this->cancel($run, $owner);

        self::assertFalse($this->holds()->isHeld($run->project, $run->cardId));
    }

    public function test_a_cancelled_stop_keeps_the_hold_of_another_run(): void
    {
        $this->boot();
        [$owner, $run] = $this->scenario('cancel-stop-keep');
        $other = $this->seedRun($this->em(), $run->project, cardId: $run->cardId, state: WorkerRunState::Stopped);
        $this->seedCommand($this->em(), $run);
        $this->holds()->hold($run->project, $run->cardId, $other, $owner);

        $this->cancel($run, $owner);

        $hold = $this->service(CardHoldRepository::class)->findOneOfCard($run->project, $run->cardId);
        self::assertNotNull($hold);
        self::assertSame((string) $other->id, (string) $hold->stoppedRun?->id);
    }

    public function test_a_cancelled_stop_keeps_the_hold_while_a_stop_of_another_run_waits(): void
    {
        $this->boot();
        [$owner, $run] = $this->scenario('cancel-stop-other-waits');
        $other = $this->seedRun($this->em(), $run->project, cardId: $run->cardId, state: WorkerRunState::Queued);
        $this->seedCommand($this->em(), $run);
        $this->seedCommand($this->em(), $other);
        $this->holds()->hold($run->project, $run->cardId, $run, $owner);

        $this->cancel($run, $owner);

        self::assertTrue($this->holds()->isHeld($run->project, $run->cardId));
    }

    /** A stop of another run went through after the hold was written, so a later cancel keeps the card held. */
    public function test_a_cancelled_stop_keeps_the_hold_a_taken_stop_of_another_run_needs(): void
    {
        $this->boot();
        [$owner, $run] = $this->scenario('cancel-stop-other-taken');
        $first = $this->seedRun($this->em(), $run->project, cardId: $run->cardId, state: WorkerRunState::Running);
        $taken = $this->seedRun($this->em(), $run->project, cardId: $run->cardId, state: WorkerRunState::Stopped);
        $this->seedCommand($this->em(), $first, BridgeCommandState::Cancelled, new \DateTimeImmutable('2026-09-29 12:03:00'));
        $this->holds()->hold($run->project, $run->cardId, $first, $owner);
        $this->seedCommand($this->em(), $taken, BridgeCommandState::Done, new \DateTimeImmutable('2026-09-29 12:03:00'));
        $this->seedCommand($this->em(), $run, requestedAt: new \DateTimeImmutable('2026-09-29 12:03:00'));

        $this->cancel($run, $owner);

        self::assertTrue($this->holds()->isHeld($run->project, $run->cardId));
    }

    public function test_the_last_cancelled_stop_of_a_card_releases_its_hold(): void
    {
        $this->boot();
        [$owner, $run] = $this->scenario('cancel-stop-both');
        $other = $this->seedRun($this->em(), $run->project, cardId: $run->cardId, state: WorkerRunState::Queued);
        $this->seedCommand($this->em(), $run);
        $this->seedCommand($this->em(), $other);
        $this->holds()->hold($run->project, $run->cardId, $run, $owner);

        $this->cancel($run, $owner);
        $this->cancel($other, $owner);

        self::assertFalse($this->holds()->isHeld($run->project, $run->cardId));
    }

    public function test_a_cancelled_resume_keeps_the_hold_of_the_card(): void
    {
        $this->boot();
        [$owner, $run] = $this->scenario('cancel-resume-keep');
        $this->seedCommand($this->em(), $run, kind: BridgeCommandKind::ResumeRun);
        $this->holds()->hold($run->project, $run->cardId, $run, $owner);

        $this->cancel($run, $owner);

        self::assertTrue($this->holds()->isHeld($run->project, $run->cardId));
    }

    /** The clock goes in before any service reads it. */
    private function boot(): void
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock(self::NOW));
    }

    /** @return array{User, WorkerRun} */
    private function scenario(string $name): array
    {
        $em = $this->em();
        $owner = $this->user($em, $name.'@example.com');
        $project = $this->project($em, $owner, 'Project '.substr(md5($name), 0, 8));
        $run = $this->seedRun($em, $project, state: WorkerRunState::Running);

        return [$owner, $run];
    }

    private function cancel(WorkerRun $run, User $by): BridgeCommand
    {
        return $this->service(CancelBridgeCommandHandler::class)(new CancelBridgeCommandCommand($run, $by));
    }

    private function holds(): CardHolds
    {
        return $this->service(CardHolds::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
