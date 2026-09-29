<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\RequestBridgeCommandCommand;
use App\Module\Bridge\Command\RequestBridgeCommandHandler;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Service\BridgeCommandTtl;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\RecordingAuditor;
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
        [$owner, $run] = $this->scenario('command-stored');
        $run->cardColumn = 'implementation';
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
        $run = $this->seedRun($em, $project, bridgeId: Uuid::v4());
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

        $this->request($run, BridgeCommandKind::ResumeRun, $owner);

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

    /** The clock goes in before any service reads it. */
    private function boot(): void
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock(self::NOW));
    }

    /** @return array{User, WorkerRun} */
    private function scenario(string $name, ?Uuid $runKey = new Uuid('0199a1b2-0000-7000-8000-000000000001')): array
    {
        $em = $this->em();
        $owner = $this->user($em, $name.'@example.com');
        $project = $this->project($em, $owner, 'Project '.substr(md5($name), 0, 8));
        $bridge = $this->seedBridge($em, $owner);
        $run = $this->seedRun($em, $project, cardNumber: 7, bridgeId: $bridge->id, state: WorkerRunState::Running, runKey: $runKey);

        return [$owner, $run];
    }

    private function request(WorkerRun $run, BridgeCommandKind $kind, User $by, ?string $reason = null): BridgeCommand
    {
        $handler = self::getContainer()->get(RequestBridgeCommandHandler::class);
        self::assertInstanceOf(RequestBridgeCommandHandler::class, $handler);

        return $handler(new RequestBridgeCommandCommand($run, $kind, $by, $reason));
    }

    /** @param non-empty-array<string, string> $errors */
    private function assertRefused(array $errors, WorkerRun $run, User $by, int $expectedCommands = 0, ?string $reason = null): void
    {
        try {
            $this->request($run, BridgeCommandKind::StopRun, $by, $reason);
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame($errors, $e->errors);
        }
        self::assertSame($expectedCommands, $this->countCommands($this->em()));
        self::assertSame([], $this->outboxPayloads());
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
