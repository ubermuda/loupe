<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\Command\RequestBridgeCommandCommand;
use App\Module\Bridge\Command\RequestBridgeCommandHandler;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\CardHoldRepository;
use App\Module\Bridge\Service\BridgeCommandTtl;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\RecordingAuditor;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class RequestBridgeCommandHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-09-29T12:00:00+00:00';

    public function test_a_command_is_stored_and_written_to_the_outbox(): void
    {
        $this->boot();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        [$owner, $run] = $this->scenario('command-stored', state: WorkerRunState::Unfinished, cardColumn: 'implementation');
        $run->cardColumn = 'implementation';
        $run->resumeIndex = 2;
        $this->em()->flush();

        $command = $this->request($run, BridgeCommandKind::ResumeRun, $owner, '  resume it  ');

        $this->em()->clear();
        $stored = $this->em()->find(BridgeCommand::class, $command->id);
        self::assertInstanceOf(BridgeCommand::class, $stored);
        self::assertSame((string) $owner->id, (string) $stored->owner->id);
        self::assertSame((string) $run->bridgeId, (string) $stored->bridgeId);
        self::assertSame((string) $run->project->id, (string) $stored->project->id);
        self::assertSame((string) $run->id, (string) $stored->workerRun->id);
        self::assertSame(BridgeCommandKind::ResumeRun, $stored->kind);
        self::assertSame(BridgeCommandState::Pending, $stored->state);
        self::assertSame('resume it', $stored->reason);
        self::assertSame((string) $owner->id, (string) $stored->requestedBy?->id);
        self::assertSame(self::NOW, $stored->requestedAt->format(\DateTimeInterface::ATOM));
        self::assertSame('2026-09-29T12:15:00+00:00', $stored->expiresAt->format(\DateTimeInterface::ATOM));
        self::assertNull($stored->settledAt);

        self::assertSame([[
            'type' => 'bridge.command',
            'projectId' => (string) $run->project->id,
            'subject' => ['type' => 'bridge-command', 'id' => (string) $command->id],
            'commandId' => (string) $command->id,
            'kind' => 'resume-run',
            'bridgeId' => (string) $run->bridgeId,
            'runKey' => (string) $run->runKey,
            'sessionId' => (string) $run->sessionId,
            'cardId' => (string) $run->cardId,
            'cardNumber' => 7,
            'ruleName' => 'plan',
            'cardColumn' => 'implementation',
            'resumeIndex' => 2,
            'expiresAt' => '2026-09-29T12:15:00+00:00',
        ]], $this->outboxPayloads());

        $record = $audit->record('bridge.command_requested');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame((string) $command->id, $record->subject?->id);
        self::assertSame('resume-run', $record->context['kind']);
        self::assertSame((string) $run->id, $record->context['runId']);
    }

    public function test_the_payload_keeps_the_absent_run_fields_null(): void
    {
        $this->boot();
        [$owner, $run] = $this->scenario('command-nulls', runKey: null);
        $run->sessionId = null;
        $this->em()->flush();

        $this->request($run, BridgeCommandKind::StopRun, $owner);

        $payload = $this->outboxPayloads()[0];
        self::assertNull($payload['runKey']);
        self::assertNull($payload['sessionId']);
        self::assertNull($payload['cardColumn']);
        self::assertNull($payload['resumeIndex']);
        self::assertSame('stop-run', $payload['kind']);
    }

    public function test_the_lifetime_follows_the_flag(): void
    {
        $this->boot();
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[BridgeCommandTtl::FLAG]->value = 30;
        $this->em()->flush();
        [$owner, $run] = $this->scenario('command-ttl');

        $command = $this->request($run, BridgeCommandKind::StopRun, $owner);

        self::assertSame('2026-09-29T12:30:00+00:00', $command->expiresAt->format(\DateTimeInterface::ATOM));
    }

    public function test_a_run_with_no_bridge_is_refused(): void
    {
        $this->boot();
        $em = $this->em();
        $owner = $this->user($em, 'command-no-bridge@example.com');
        $project = $this->project($em, $owner, 'No Bridge Project');
        $run = $this->seedRun($em, $project, state: WorkerRunState::Running, kind: WorkerRunKind::Interactive);

        $this->assertRefused(['run' => 'bridge.command.error.no_bridge'], $run, $owner);
    }

    public function test_a_run_whose_bridge_the_owner_does_not_hold_is_refused(): void
    {
        $this->boot();
        $em = $this->em();
        $owner = $this->user($em, 'command-unknown-bridge@example.com');
        $project = $this->project($em, $owner, 'Unknown Bridge Project');
        $run = $this->seedRun($em, $project, bridgeId: Uuid::v4(), state: WorkerRunState::Running);
        // Another account holds a row under the same bridge id.
        $this->seedBridge($em, $this->user($em, 'command-unknown-bridge-other@example.com'), $run->bridgeId);

        $this->assertRefused(['run' => 'bridge.command.error.unknown_bridge'], $run, $owner);
    }

    public function test_a_run_that_holds_a_pending_command_is_refused(): void
    {
        $this->boot();
        [$owner, $run] = $this->scenario('command-pending');
        // Past its expiry and not swept yet: it still holds the unique index.
        $this->seedCommand($this->em(), $run, expiresAt: new \DateTimeImmutable('2026-09-29 11:00:00'));

        $this->assertRefused(['run' => 'bridge.command.error.pending'], $run, $owner, expectedCommands: 1);
    }

    public function test_a_settled_command_does_not_block_a_new_one(): void
    {
        $this->boot();
        [$owner, $run] = $this->scenario('command-settled');
        $this->seedCommand($this->em(), $run, state: BridgeCommandState::Expired);

        $this->request($run, BridgeCommandKind::StopRun, $owner);

        self::assertSame(2, $this->countCommands($this->em()));
    }

    public function test_a_reason_over_the_cap_is_refused(): void
    {
        $this->boot();
        [$owner, $run] = $this->scenario('command-reason');

        $this->assertRefused(
            ['reason' => 'bridge.command.error.reason_too_long'],
            $run,
            $owner,
            reason: str_repeat('a', BridgeCommand::MAX_REASON_LENGTH + 1),
        );
    }

    public function test_a_blank_reason_is_stored_as_null(): void
    {
        $this->boot();
        [$owner, $run] = $this->scenario('command-blank-reason');

        $command = $this->request($run, BridgeCommandKind::StopRun, $owner, '   ');

        self::assertNull($command->reason);
    }

    public function test_an_interactive_run_is_refused(): void
    {
        $this->boot();
        $em = $this->em();
        $owner = $this->user($em, 'command-interactive@example.com');
        $bridge = $this->commandBridge($owner);
        $run = $this->seedRun($em, $this->project($em, $owner, 'Interactive Project'), bridgeId: $bridge->id, state: WorkerRunState::Running, kind: WorkerRunKind::Interactive);

        $this->assertRefused(['run' => 'bridge.command.error.not_controllable'], $run, $owner);
    }

    public function test_a_resume_of_a_run_with_no_session_is_refused(): void
    {
        $this->boot();
        [$owner, $run] = $this->scenario('command-no-session', state: WorkerRunState::Unfinished);
        $run->sessionId = null;
        $this->em()->flush();

        $this->assertRefused(['run' => 'bridge.command.error.no_session'], $run, $owner, kind: BridgeCommandKind::ResumeRun);
    }

    /** @return iterable<string, array{WorkerRunState}> */
    public static function notResumable(): iterable
    {
        yield 'running' => [WorkerRunState::Running];
        yield 'stopping' => [WorkerRunState::Stopping];
        yield 'succeeded' => [WorkerRunState::Succeeded];
        yield 'waiting on the forge' => [WorkerRunState::WaitingOnForge];
    }

    #[DataProvider('notResumable')]
    public function test_a_resume_of_a_run_that_runs_or_finished_is_refused(WorkerRunState $state): void
    {
        $this->boot();
        [$owner, $run] = $this->scenario('command-not-resumable-'.$state->value, state: $state);

        $this->assertRefused(['run' => 'bridge.command.error.not_resumable'], $run, $owner, kind: BridgeCommandKind::ResumeRun);
    }

    /** @return iterable<string, array{?string}> */
    public static function otherColumns(): iterable
    {
        yield 'another column' => ['review'];
        yield 'a deleted card' => [null];
    }

    #[DataProvider('otherColumns')]
    public function test_a_resume_after_the_card_left_the_column_is_refused(?string $column): void
    {
        $this->boot();
        [$owner, $run] = $this->scenario('command-card-left-'.($column ?? 'none'), state: WorkerRunState::Blocked, cardColumn: $column);
        $run->cardColumn = 'implementation';
        $this->em()->flush();

        $this->assertRefused(['run' => 'bridge.command.error.card_left'], $run, $owner, kind: BridgeCommandKind::ResumeRun);
    }

    public function test_a_resume_of_a_run_with_no_column_skips_the_column_check(): void
    {
        $this->boot();
        [$owner, $run] = $this->scenario('command-no-column', state: WorkerRunState::Lost);

        $this->request($run, BridgeCommandKind::ResumeRun, $owner);

        self::assertSame(1, $this->countCommands($this->em()));
    }

    /** @return iterable<string, array{WorkerRunState}> */
    public static function notStoppable(): iterable
    {
        yield 'stopping' => [WorkerRunState::Stopping];
        yield 'stopped' => [WorkerRunState::Stopped];
        yield 'failed' => [WorkerRunState::Failed];
    }

    #[DataProvider('notStoppable')]
    public function test_a_stop_of_a_run_that_does_not_run_is_refused(WorkerRunState $state): void
    {
        $this->boot();
        [$owner, $run] = $this->scenario('command-not-stoppable-'.$state->value, state: $state);

        $this->assertRefused(['run' => 'bridge.command.error.not_stoppable'], $run, $owner);
    }

    public function test_a_bridge_that_takes_no_commands_is_refused(): void
    {
        $this->boot();
        $em = $this->em();
        $owner = $this->user($em, 'command-outdated@example.com');
        $bridge = $this->seedBridge($em, $owner);
        $run = $this->seedRun($em, $this->project($em, $owner, 'Outdated Project'), bridgeId: $bridge->id, state: WorkerRunState::Running);

        $this->assertRefused(['run' => 'bridge.command.error.bridge_outdated'], $run, $owner);
    }

    /** @return iterable<string, array{WorkerRunState}> */
    public static function rerunnable(): iterable
    {
        yield 'failed' => [WorkerRunState::Failed];
        yield 'timed out' => [WorkerRunState::TimedOut];
        yield 'lost' => [WorkerRunState::Lost];
    }

    #[DataProvider('rerunnable')]
    public function test_a_rerun_of_an_ended_command_run_is_stored_and_holds_nothing(WorkerRunState $state): void
    {
        $this->boot();
        [$owner, $run] = $this->scenario('command-rerun-'.$state->value, state: $state, kind: WorkerRunKind::Command, cliVersion: '1.6.0');

        $command = $this->request($run, BridgeCommandKind::RerunCommand, $owner);

        self::assertSame(BridgeCommandKind::RerunCommand, $command->kind);
        self::assertSame(1, $this->countCommands($this->em()));
        $payloads = $this->outboxPayloads();
        self::assertCount(1, $payloads);
        self::assertSame('rerun-command', $payloads[0]['kind']);
        self::assertSame((string) $run->runKey, $payloads[0]['runKey']);
        self::assertNull($payloads[0]['sessionId']);
        self::assertFalse($this->service(CardHolds::class)->isHeld($run->project, $run->cardId));
    }

    public function test_a_rerun_of_a_worker_run_is_refused(): void
    {
        $this->boot();
        [$owner, $run] = $this->scenario('command-rerun-worker', state: WorkerRunState::Failed, cliVersion: '1.6.0');

        $this->assertRefused(['run' => 'bridge.command.error.not_a_command'], $run, $owner, kind: BridgeCommandKind::RerunCommand);
    }

    /** @return iterable<string, array{WorkerRunState}> */
    public static function notRerunnable(): iterable
    {
        yield 'queued' => [WorkerRunState::Queued];
        yield 'running' => [WorkerRunState::Running];
        yield 'succeeded' => [WorkerRunState::Succeeded];
        yield 'stopped' => [WorkerRunState::Stopped];
        yield 'not started' => [WorkerRunState::NotStarted];
    }

    #[DataProvider('notRerunnable')]
    public function test_a_rerun_of_a_command_run_that_did_not_fail_is_refused(WorkerRunState $state): void
    {
        $this->boot();
        [$owner, $run] = $this->scenario('command-rerun-not-'.$state->value, state: $state, kind: WorkerRunKind::Command, cliVersion: '1.6.0');

        $this->assertRefused(['run' => 'bridge.command.error.not_rerunnable'], $run, $owner, kind: BridgeCommandKind::RerunCommand);
    }

    /** @return iterable<string, array{string}> */
    public static function versionsBeforeReruns(): iterable
    {
        yield 'an older release' => ['1.5.9'];
        yield 'a dev build' => ['b4e39aa7'];
    }

    #[DataProvider('versionsBeforeReruns')]
    public function test_a_rerun_on_a_bridge_older_than_reruns_is_refused(string $cliVersion): void
    {
        $this->boot();
        [$owner, $run] = $this->scenario('command-rerun-old-'.$cliVersion, state: WorkerRunState::Failed, kind: WorkerRunKind::Command, cliVersion: $cliVersion);

        $this->assertRefused(['run' => 'bridge.command.error.bridge_outdated'], $run, $owner, kind: BridgeCommandKind::RerunCommand);
    }

    public function test_a_resume_of_a_command_run_is_refused_for_its_missing_session(): void
    {
        $this->boot();
        [$owner, $run] = $this->scenario('command-resume-command', state: WorkerRunState::Failed, kind: WorkerRunKind::Command, cliVersion: '1.6.0');

        $this->assertRefused(['run' => 'bridge.command.error.no_session'], $run, $owner, kind: BridgeCommandKind::ResumeRun);
    }

    public function test_a_stop_holds_the_card_for_the_run(): void
    {
        $this->boot();
        [$owner, $run] = $this->scenario('command-stop-holds');

        $this->request($run, BridgeCommandKind::StopRun, $owner);

        $this->em()->clear();
        $hold = $this->service(CardHoldRepository::class)->findOneOfCard($this->reloadProject($run), $run->cardId);
        self::assertNotNull($hold);
        self::assertSame((string) $run->id, (string) $hold->stoppedRun?->id);
        self::assertSame((string) $owner->id, (string) $hold->heldBy?->id);
    }

    public function test_a_stop_of_a_preparing_run_is_queued(): void
    {
        $this->boot();
        [$owner, $run] = $this->scenario('command-stop-preparing', state: WorkerRunState::Preparing);

        $this->request($run, BridgeCommandKind::StopRun, $owner);

        self::assertSame(1, $this->countCommands($this->em()));
    }

    /** The bridge ends the hold when it takes the resume, so a withdrawn resume leaves the card held. */
    public function test_a_resume_keeps_the_hold_until_the_bridge_takes_it(): void
    {
        $this->boot();
        [$owner, $run] = $this->scenario('command-resume-keeps', state: WorkerRunState::Stopped);
        $this->service(CardHolds::class)->hold($run->project, $run->cardId, $run, $owner);

        $this->request($run, BridgeCommandKind::ResumeRun, $owner);

        self::assertSame(1, $this->countCommands($this->em()));
        self::assertTrue($this->service(CardHolds::class)->isHeld($run->project, $run->cardId));
    }

    /** The bridge reported the stop, and its ack has not arrived yet. */
    public function test_a_resume_while_a_stop_waits_is_refused_and_keeps_the_hold(): void
    {
        $this->boot();
        [$owner, $run] = $this->scenario('command-resume-stop-pending', state: WorkerRunState::Stopped);
        $this->service(CardHolds::class)->hold($run->project, $run->cardId, $run, $owner);
        $this->seedCommand($this->em(), $run);

        $this->assertRefused(['run' => 'bridge.command.error.pending'], $run, $owner, expectedCommands: 1, kind: BridgeCommandKind::ResumeRun);
        self::assertTrue($this->service(CardHolds::class)->isHeld($run->project, $run->cardId));
    }

    /** The clock goes in before any service reads it. */
    private function boot(): void
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock(self::NOW));
    }

    /**
     * @param ?string $cardColumn with a value, the run's card sits in a board column of that slug
     *
     * @return array{User, WorkerRun}
     */
    private function scenario(
        string $name,
        ?Uuid $runKey = new Uuid('0199a1b2-0000-7000-8000-000000000001'),
        WorkerRunState $state = WorkerRunState::Running,
        ?string $cardColumn = null,
        WorkerRunKind $kind = WorkerRunKind::Worker,
        string $cliVersion = 'b4e39aa7',
    ): array {
        $em = $this->em();
        $owner = $this->user($em, $name.'@example.com');
        $project = $this->project($em, $owner, 'Project '.substr(md5($name), 0, 8));
        $bridge = $this->commandBridge($owner, $cliVersion);
        $cardId = null;
        if (null !== $cardColumn) {
            $card = new Card($project, new BoardColumn($project, ucfirst($cardColumn), $cardColumn, 0), 'Held card', '', 7);
            $em->persist($card->column);
            $em->persist($card);
            $em->flush();
            $cardId = $card->id;
        }
        $run = $this->seedRun($em, $project, cardNumber: 7, bridgeId: $bridge->id, cardId: $cardId, state: $state, runKey: $runKey, kind: $kind);

        return [$owner, $run];
    }

    private function commandBridge(User $owner, string $cliVersion = 'b4e39aa7'): Bridge
    {
        $bridge = $this->seedBridge($this->em(), $owner, cliVersion: $cliVersion);
        $bridge->capabilities = [Bridge::CAPABILITY_COMMANDS];
        $this->em()->flush();

        return $bridge;
    }

    private function reloadProject(WorkerRun $run): Project
    {
        $project = $this->em()->find(Project::class, $run->project->id);
        self::assertInstanceOf(Project::class, $project);

        return $project;
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

    private function request(WorkerRun $run, BridgeCommandKind $kind, User $by, ?string $reason = null): BridgeCommand
    {
        $handler = self::getContainer()->get(RequestBridgeCommandHandler::class);
        self::assertInstanceOf(RequestBridgeCommandHandler::class, $handler);

        return $handler(new RequestBridgeCommandCommand($run, $kind, $by, $reason));
    }

    /** @param non-empty-array<string, string> $errors */
    private function assertRefused(array $errors, WorkerRun $run, User $by, int $expectedCommands = 0, ?string $reason = null, BridgeCommandKind $kind = BridgeCommandKind::StopRun): void
    {
        try {
            $this->request($run, $kind, $by, $reason);
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame($errors, $e->errors);
        }
        self::assertSame($expectedCommands, $this->countCommands($this->em()));
        self::assertSame([], $this->outboxPayloads());
        if (BridgeCommandKind::StopRun === $kind) {
            self::assertFalse($this->service(CardHolds::class)->isHeld($run->project, $run->cardId));
        }
    }

    /** @return list<array<string, mixed>> */
    private function outboxPayloads(): array
    {
        /** @var list<string> $payloads */
        $payloads = $this->em()->getConnection()->fetchFirstColumn(
            "SELECT payload FROM outbox_events WHERE type = 'bridge.command' ORDER BY sequence",
        );

        return array_map(static fn (string $payload): array => json_decode($payload, true, flags: \JSON_THROW_ON_ERROR), $payloads);
    }
}
