<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\RecordBridgeHeartbeatCommand;
use App\Module\Bridge\Command\RecordBridgeHeartbeatHandler;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Service\CliCompatibility;
use App\Module\Bridge\ValueObject\CliUpdateState;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\RecordingAuditor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\AuditOutcome;

final class RecordBridgeHeartbeatHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_the_first_heartbeat_of_a_bridge_is_audited(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-audit-first@example.com');
        $project = $this->project($em, $owner, 'Audited Heartbeat');
        $bridgeId = Uuid::v4();

        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [(string) $project->id], 'b4e39aa7'));

        $record = $audit->record('bridge.bridge_registered');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(['bridgeId' => (string) $bridgeId, 'projects' => 1], $record->context);
    }

    /** A replace arrives once a minute per bridge, so it must leave the trail alone. */
    public function test_a_heartbeat_that_replaces_the_row_is_not_audited(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-audit-replace@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7'));
        self::assertCount(1, $audit->records('bridge.bridge_registered'));

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7'));

        self::assertCount(1, $audit->records('bridge.bridge_registered'));
        self::assertSame(['bridge.bridge_registered'], $audit->operations());
    }

    public function test_it_stores_the_update_report_and_answers_the_cli_range(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-update-report@example.com');

        $result = $this->handler()(new RecordBridgeHeartbeatCommand($owner, Uuid::v4(), [], '1.2.0', CliUpdateState::Blocked, '1.3.0'));

        self::assertSame(CliCompatibility::RANGE, $result->cliRange);
        self::assertSame(CliUpdateState::Blocked, $result->bridge->updateState);
        self::assertSame('1.3.0', $result->bridge->updateVersion);
    }

    public function test_the_first_heartbeat_stores_the_hook_report(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-hooks-first@example.com');
        $bridgeId = Uuid::v4();

        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', hooks: [self::hook()]));

        self::assertSame([self::hook()], $this->reload($owner, $bridgeId)->hooks);
    }

    public function test_a_later_heartbeat_replaces_the_hook_report(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-hooks-replace@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();
        $failed = ['outcome' => 'failed', 'error' => 'exit 1'] + self::hook();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', hooks: [self::hook()]));
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', hooks: [$failed]));

        self::assertSame([$failed], $this->reload($owner, $bridgeId)->hooks);
    }

    /** An older bridge sends no report, and its heartbeat must not clear the rows a newer one sent. */
    public function test_a_heartbeat_without_a_hook_report_keeps_the_stored_rows(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-hooks-keep@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', hooks: [self::hook()]));
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7'));

        self::assertSame([self::hook()], $this->reload($owner, $bridgeId)->hooks);
    }

    public function test_an_empty_hook_report_clears_the_stored_rows(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-hooks-clear@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', hooks: [self::hook()]));
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', hooks: []));

        self::assertSame([], $this->reload($owner, $bridgeId)->hooks);
    }

    public function test_a_row_written_before_the_hooks_column_reads_as_no_hooks(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-hooks-legacy@example.com');
        $bridgeId = Uuid::v4();
        $em->getConnection()->executeStatement(
            'INSERT INTO bridges (owner_id, id, projects, cli_version, last_seen_at) VALUES (?, ?, ?, ?, ?)',
            [(string) $owner->id, (string) $bridgeId, '[]', 'b4e39aa7', '2026-09-14 16:00:00'],
        );

        self::assertSame([], $this->reload($owner, $bridgeId)->hooks);
    }

    /** @return array{package: string, ref: string, event: string, lastRunAt: ?string, outcome: string, error: ?string} */
    private static function hook(): array
    {
        return [
            'package' => 'github:acme/loupe-hooks',
            'ref' => 'v1.2.0',
            'event' => 'start',
            'lastRunAt' => '2026-09-14T16:00:00+00:00',
            'outcome' => 'ok',
            'error' => null,
        ];
    }

    private function reload(User $owner, Uuid $bridgeId): Bridge
    {
        $this->em()->clear();
        $bridges = self::getContainer()->get(BridgeRepository::class);
        self::assertInstanceOf(BridgeRepository::class, $bridges);
        $bridge = $bridges->findOneByOwnerAndId($owner, $bridgeId);
        self::assertInstanceOf(Bridge::class, $bridge);

        return $bridge;
    }

    private function handler(): RecordBridgeHeartbeatHandler
    {
        $handler = self::getContainer()->get(RecordBridgeHeartbeatHandler::class);
        self::assertInstanceOf(RecordBridgeHeartbeatHandler::class, $handler);

        return $handler;
    }
}
