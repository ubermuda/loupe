<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Mercure\LiveUpdatePublisher;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\AcknowledgeBridgeCommandCommand;
use App\Module\Bridge\Command\AcknowledgeBridgeCommandHandler;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\Service\WorkerRunChangedPublisher;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\RecordingAuditor;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\AuditOutcome;

final class AcknowledgeBridgeCommandHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-09-29T12:05:00+00:00';

    public function test_a_settled_command_is_audited_without_the_reason(): void
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock(self::NOW));
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $em = $this->em();
        $owner = $this->user($em, 'ack-handler-audit@example.com');
        $run = $this->seedRun($em, $this->project($em, $owner, 'Ack Handler Audit'));
        $command = $this->seedCommand($em, $run);

        $result = $this->handler()(new AcknowledgeBridgeCommandCommand($owner, $command->bridgeId, self::idOf($command), BridgeCommandState::Refused, 'busy'));

        self::assertTrue($result->settled);
        self::assertSame(BridgeCommandState::Refused, $result->command?->state);
        $record = $audit->record('bridge.command_settled');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame([
            'commandId' => (string) $command->id,
            'state' => 'refused',
            'kind' => 'stop-run',
            'projectId' => (string) $run->project->id,
            'runId' => (string) $run->id,
            'bridgeId' => (string) $command->bridgeId,
        ], $record->context);
    }

    public function test_a_repeated_ack_is_not_audited(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $em = $this->em();
        $owner = $this->user($em, 'ack-handler-repeat@example.com');
        $command = $this->seedCommand($em, $this->seedRun($em, $this->project($em, $owner, 'Ack Handler Repeat')));
        $ack = new AcknowledgeBridgeCommandCommand($owner, $command->bridgeId, self::idOf($command), BridgeCommandState::Done, null);

        $this->handler()($ack);
        $result = $this->handler()($ack);

        self::assertFalse($result->settled);
        self::assertSame(BridgeCommandState::Done, $result->command?->state);
        self::assertCount(1, $audit->records('bridge.command_settled'));
    }

    /** The expiry sweep writes by bulk update, so the handler must read the row and not a managed copy. */
    public function test_a_command_the_sweep_expired_after_it_was_loaded_stays_expired(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'ack-handler-sweep@example.com');
        $command = $this->seedCommand($em, $this->seedRun($em, $this->project($em, $owner, 'Ack Handler Sweep')));
        $em->getConnection()->executeStatement("UPDATE bridge_commands SET state = 'expired' WHERE id = ?", [(string) $command->id]);

        $result = $this->handler()(new AcknowledgeBridgeCommandCommand($owner, $command->bridgeId, self::idOf($command), BridgeCommandState::Done, null));

        self::assertFalse($result->settled);
        self::assertSame(BridgeCommandState::Expired, $result->command?->state);
    }

    /** @return iterable<string, array{BridgeCommandKind, BridgeCommandState, bool}> */
    public static function holdCases(): iterable
    {
        yield 'a resume the bridge took' => [BridgeCommandKind::ResumeRun, BridgeCommandState::Done, false];
        yield 'a resume the bridge refused' => [BridgeCommandKind::ResumeRun, BridgeCommandState::Refused, true];
        yield 'a stop the bridge took' => [BridgeCommandKind::StopRun, BridgeCommandState::Done, true];
        yield 'a rerun the bridge took' => [BridgeCommandKind::RerunCommand, BridgeCommandState::Done, true];
    }

    #[DataProvider('holdCases')]
    public function test_only_a_resume_the_bridge_took_releases_the_hold_of_the_card(BridgeCommandKind $kind, BridgeCommandState $state, bool $held): void
    {
        [$owner, $run, $command] = $this->heldScenario('ack-handler-hold-'.$kind->value.'-'.$state->value, $kind);

        $result = $this->handler()(new AcknowledgeBridgeCommandCommand($owner, $command->bridgeId, self::idOf($command), $state, null));

        self::assertTrue($result->settled);
        self::assertSame($held, $this->holds()->isHeld($run->project, $run->cardId));
    }

    /** The bridge took the resume, and its ack arrived after a person cancelled the request. */
    public function test_a_late_ack_of_a_taken_resume_releases_the_hold(): void
    {
        $published = [];
        [$owner, $run, $command] = $this->heldScenario('ack-handler-hold-late', BridgeCommandKind::ResumeRun, $published);
        $this->em()->getConnection()->executeStatement("UPDATE bridge_commands SET state = 'cancelled' WHERE id = ?", [(string) $command->id]);

        $result = $this->handler()(new AcknowledgeBridgeCommandCommand($owner, $command->bridgeId, self::idOf($command), BridgeCommandState::Done, null));
        $live = self::getContainer()->get(LiveUpdatePublisher::class);
        self::assertInstanceOf(LiveUpdatePublisher::class, $live);
        $live->publish();

        self::assertFalse($result->settled);
        self::assertFalse($this->holds()->isHeld($run->project, $run->cardId));
        self::assertSame([WorkerRunChangedPublisher::TYPE], array_map(static fn (Update $update): mixed => json_decode($update->getData(), true)['type'] ?? null, $published));
    }

    /** A person stopped the resumed run before the ack of the resume arrived, and the stop kept the hold the card had. */
    public function test_a_stop_asked_after_the_resume_keeps_the_hold(): void
    {
        [$owner, $run, $command] = $this->heldScenario('ack-handler-hold-later-stop', BridgeCommandKind::ResumeRun);
        $this->seedCommand($this->em(), $run, BridgeCommandState::Done, new \DateTimeImmutable('2026-09-29 12:01:00'));

        $result = $this->handler()(new AcknowledgeBridgeCommandCommand($owner, $command->bridgeId, self::idOf($command), BridgeCommandState::Done, null));

        self::assertTrue($result->settled);
        self::assertTrue($this->holds()->isHeld($run->project, $run->cardId));
    }

    /**
     * @param list<Update> $published
     *
     * @return array{User, WorkerRun, BridgeCommand}
     */
    private function heldScenario(string $name, BridgeCommandKind $kind, array &$published = []): array
    {
        self::bootKernel();
        // Before anything builds the hub, so the handler publishes through this one.
        self::getContainer()->set('mercure.hub.default', new MockHub('http://mercure/.well-known/mercure', new StaticTokenProvider('token'), static function (Update $update) use (&$published): string {
            $published[] = $update;

            return 'id';
        }));
        $em = $this->em();
        $owner = $this->user($em, $name.'@example.com');
        $run = $this->seedRun($em, $this->project($em, $owner, 'Ack Handler Hold'));
        $command = $this->seedCommand($em, $run, kind: $kind);
        $this->holds()->hold($run->project, $run->cardId, $run, $owner);

        return [$owner, $run, $command];
    }

    private function holds(): CardHolds
    {
        $holds = self::getContainer()->get(CardHolds::class);
        self::assertInstanceOf(CardHolds::class, $holds);

        return $holds;
    }

    public function test_an_unknown_command_answers_no_command(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $owner = $this->user($this->em(), 'ack-handler-unknown@example.com');

        $result = $this->handler()(new AcknowledgeBridgeCommandCommand($owner, Uuid::v4(), Uuid::v4(), BridgeCommandState::Done, null));

        self::assertNull($result->command);
        self::assertFalse($result->settled);
        self::assertSame([], $audit->operations());
    }

    private static function idOf(BridgeCommand $command): Uuid
    {
        return $command->id ?? throw new \LogicException('A flushed command has an id.');
    }

    private function handler(): AcknowledgeBridgeCommandHandler
    {
        $handler = self::getContainer()->get(AcknowledgeBridgeCommandHandler::class);
        self::assertInstanceOf(AcknowledgeBridgeCommandHandler::class, $handler);

        return $handler;
    }
}
