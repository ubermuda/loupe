<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\RecordBridgeHeartbeatCommand;
use App\Module\Bridge\Command\RecordBridgeHeartbeatHandler;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Service\CliCompatibility;
use App\Module\Bridge\Service\HostSampling;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\BridgeHostSampleReport;
use App\Module\Bridge\ValueObject\CliInstallMethod;
use App\Module\Bridge\ValueObject\CliUpdateState;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\RecordingAuditor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
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

    public function test_each_heartbeat_replaces_the_install_method(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-install-method@example.com');
        $bridgeId = Uuid::v4();

        $first = $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], '1.2.0', CliUpdateState::Off, '1.3.0', installMethod: CliInstallMethod::Homebrew));
        self::assertSame(CliInstallMethod::Homebrew, $first->bridge->installMethod);

        $second = $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], '1.2.0', CliUpdateState::Current));

        self::assertNull($second->bridge->installMethod);
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

    public function test_the_first_heartbeat_stores_the_worker_pools(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-pools-first@example.com');
        $bridgeId = Uuid::v4();

        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', workerPools: [self::pool()]));

        self::assertSame([self::pool()], $this->reload($owner, $bridgeId)->workerPools);
    }

    public function test_a_first_heartbeat_without_worker_pools_stores_none(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-pools-none@example.com');
        $bridgeId = Uuid::v4();

        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7'));

        self::assertNull($this->reload($owner, $bridgeId)->workerPools);
    }

    public function test_a_later_heartbeat_replaces_the_worker_pools(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-pools-replace@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();
        $busy = ['inUse' => 3, 'queued' => 4] + self::pool();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', workerPools: [self::pool()]));
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', workerPools: [$busy]));

        self::assertSame([$busy], $this->reload($owner, $bridgeId)->workerPools);
    }

    /** An older bridge sends no pools, and its heartbeat must not clear the rows a newer one sent. */
    public function test_a_heartbeat_without_worker_pools_keeps_the_stored_rows(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-pools-keep@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', workerPools: [self::pool()]));
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7'));

        self::assertSame([self::pool()], $this->reload($owner, $bridgeId)->workerPools);
    }

    /** The Agents page dates the counts, so a heartbeat that carried none must not make them look fresh. */
    public function test_a_heartbeat_without_worker_pools_keeps_the_time_of_the_last_report(): void
    {
        self::bootKernel();
        $clock = new MockClock('2026-09-14 16:00:00');
        self::getContainer()->set('clock', $clock);
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-pools-time@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', workerPools: [self::pool()]));
        $clock->modify('+1 minute');
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7'));

        $bridge = $this->reload($owner, $bridgeId);
        self::assertSame('2026-09-14 16:01:00', $bridge->lastSeenAt->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-14 16:00:00', $bridge->workerPoolsReportedAt?->format('Y-m-d H:i:s'));

        $clock->modify('+1 minute');
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', workerPools: []));

        self::assertSame('2026-09-14 16:02:00', $this->reload($owner, $bridgeId)->workerPoolsReportedAt?->format('Y-m-d H:i:s'));
    }

    public function test_a_bridge_that_never_reported_pools_has_no_report_time(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-pools-no-time@example.com');
        $bridgeId = Uuid::v4();

        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7'));

        self::assertNull($this->reload($owner, $bridgeId)->workerPoolsReportedAt);
    }

    public function test_an_empty_worker_pool_report_clears_the_stored_rows(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-pools-clear@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', workerPools: [self::pool()]));
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', workerPools: []));

        self::assertSame([], $this->reload($owner, $bridgeId)->workerPools);
    }

    public function test_a_first_heartbeat_answers_no_commands_and_stores_the_reports(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-first-commands@example.com');
        $bridgeId = Uuid::v4();

        $result = $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', paused: true, capabilities: ['commands']));

        self::assertSame([], $result->commands);
        self::assertFalse($result->bridge->pauseRequested);
        $stored = $this->reload($owner, $bridgeId);
        self::assertTrue($stored->pausedReported);
        self::assertSame(['commands'], $stored->capabilities);
    }

    public function test_a_later_heartbeat_answers_the_pending_commands_of_the_bridge(): void
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock('2026-09-29 12:10:00'));
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-later-commands@example.com');
        $project = $this->project($em, $owner, 'Heartbeat Handler Commands');
        $bridgeId = Uuid::v4();
        $this->seedBridge($em, $owner, $bridgeId);
        $pending = $this->seedCommand($em, $this->seedRun($em, $project, bridgeId: $bridgeId));
        $this->seedCommand($em, $this->seedRun($em, $project, bridgeId: $bridgeId), state: BridgeCommandState::Refused);

        $result = $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7'));

        self::assertSame([(string) $pending->id], array_map(static fn (BridgeCommand $c): string => (string) $c->id, $result->commands));
    }

    public function test_a_heartbeat_without_a_name_keeps_the_stored_names(): void
    {
        self::bootKernel();
        $owner = $this->user($this->em(), 'heartbeat-name-keep@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', name: 'laptop'));
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7'));

        $bridge = $this->reload($owner, $bridgeId);
        self::assertSame('laptop', $bridge->name);
        self::assertSame('laptop', $bridge->requestedName);
    }

    public function test_an_empty_name_clears_both_names(): void
    {
        self::bootKernel();
        $owner = $this->user($this->em(), 'heartbeat-name-clear@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', name: 'laptop'));
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', name: ''));

        $bridge = $this->reload($owner, $bridgeId);
        self::assertNull($bridge->name);
        self::assertNull($bridge->requestedName);
        self::assertFalse($bridge->nameClashes());
    }

    public function test_a_free_name_is_taken(): void
    {
        self::bootKernel();
        $owner = $this->user($this->em(), 'heartbeat-name-free@example.com');
        $bridgeId = Uuid::v4();

        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', name: 'laptop'));

        $bridge = $this->reload($owner, $bridgeId);
        self::assertSame('laptop', $bridge->name);
        self::assertSame('laptop', $bridge->requestedName);
        self::assertFalse($bridge->nameClashes());
    }

    public function test_a_bridge_that_holds_its_name_keeps_it(): void
    {
        self::bootKernel();
        $owner = $this->user($this->em(), 'heartbeat-name-hold@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', name: 'laptop'));
        $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', name: 'laptop'));

        self::assertSame('laptop', $this->reload($owner, $bridgeId)->name);
    }

    public function test_a_name_another_bridge_holds_is_requested_and_not_taken(): void
    {
        self::bootKernel();
        $owner = $this->user($this->em(), 'heartbeat-name-clash@example.com');
        $holder = Uuid::v4();
        $claimer = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($owner, $holder, [], 'b4e39aa7', name: 'laptop'));
        $handler(new RecordBridgeHeartbeatCommand($owner, $claimer, [], 'b4e39aa7', name: 'laptop'));

        $bridge = $this->reload($owner, $claimer);
        self::assertNull($bridge->name);
        self::assertSame('laptop', $bridge->requestedName);
        self::assertTrue($bridge->nameClashes());
        self::assertSame('laptop', $this->reload($owner, $holder)->name);
    }

    public function test_a_name_its_holder_gives_up_goes_to_the_claimer_at_its_next_heartbeat(): void
    {
        self::bootKernel();
        $owner = $this->user($this->em(), 'heartbeat-name-freed@example.com');
        $holder = Uuid::v4();
        $claimer = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($owner, $holder, [], 'b4e39aa7', name: 'laptop'));
        $handler(new RecordBridgeHeartbeatCommand($owner, $claimer, [], 'b4e39aa7', name: 'laptop'));
        $handler(new RecordBridgeHeartbeatCommand($owner, $holder, [], 'b4e39aa7', name: 'desktop'));
        $handler(new RecordBridgeHeartbeatCommand($owner, $claimer, [], 'b4e39aa7', name: 'laptop'));

        self::assertSame('desktop', $this->reload($owner, $holder)->name);
        $bridge = $this->reload($owner, $claimer);
        self::assertSame('laptop', $bridge->name);
        self::assertFalse($bridge->nameClashes());
    }

    public function test_bridges_of_different_owners_never_clash(): void
    {
        self::bootKernel();
        $em = $this->em();
        $first = $this->user($em, 'heartbeat-name-owner-one@example.com');
        $second = $this->user($em, 'heartbeat-name-owner-two@example.com');
        $bridgeId = Uuid::v4();
        $handler = $this->handler();

        $handler(new RecordBridgeHeartbeatCommand($first, Uuid::v4(), [], 'b4e39aa7', name: 'laptop'));
        $handler(new RecordBridgeHeartbeatCommand($second, $bridgeId, [], 'b4e39aa7', name: 'laptop'));

        self::assertSame('laptop', $this->reload($second, $bridgeId)->name);
    }

    /** @return array{name: string, size: int, inUse: int, queued: int} */
    private static function pool(): array
    {
        return ['name' => 'default', 'size' => 3, 'inUse' => 1, 'queued' => 0];
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

    public function test_the_first_heartbeat_stores_its_samples_under_the_owner(): void
    {
        self::bootKernel();
        $em = $this->em();
        $this->storeHostSampling('true');
        $owner = $this->user($em, 'heartbeat-samples-owner@example.com');
        $other = $this->user($em, 'heartbeat-samples-other@example.com');
        $bridgeId = Uuid::v4();
        $this->seedBridge($em, $other, $bridgeId);

        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', hostSamples: [self::sample('2026-10-07 12:00:00')]));

        self::assertSame(
            [['owner_id' => (string) $owner->id, 'bridge_id' => (string) $bridgeId, 'sampled_at' => '2026-10-07 12:00:00']],
            $em->getConnection()->fetchAllAssociative('SELECT owner_id, bridge_id, sampled_at FROM bridge_host_samples'),
        );
    }

    public function test_samples_are_dropped_while_sampling_is_off(): void
    {
        self::bootKernel();
        $em = $this->em();
        $this->storeHostSampling('false');
        $owner = $this->user($em, 'heartbeat-samples-dropped@example.com');
        $bridgeId = Uuid::v4();

        $result = $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7', hostSamples: [self::sample('2026-10-07 12:00:00')]));

        self::assertSame('b4e39aa7', $result->bridge->cliVersion);
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_host_samples'));
    }

    /** The run ended before its samples arrived, so the heartbeat must rewrite its fact row. */
    public function test_samples_rewrite_the_fact_of_an_ended_run_on_the_bridge(): void
    {
        self::bootKernel();
        $em = $this->em();
        $this->storeHostSampling('true');
        $owner = $this->user($em, 'heartbeat-samples-facts@example.com');
        $project = $this->project($em, $owner, 'Sampled Facts');
        $bridge = $this->seedBridge($em, $owner);
        $inside = $this->seedRun($em, $project, bridgeId: $bridge->id);
        $outside = $this->seedRun($em, $project, cardNumber: 2, bridgeId: $bridge->id, endedAt: new \DateTimeImmutable('2026-01-01 12:05:00'));
        $em->getConnection()->executeStatement("UPDATE bridge_worker_runs SET started_at = '2026-01-01 12:00:00' WHERE id = ?", [(string) $outside->id]);
        $em->getConnection()->executeStatement('UPDATE bridge_worker_run_facts SET peak_mem_bytes = 1 WHERE run_id = ?', [(string) $outside->id]);

        $this->handler()(new RecordBridgeHeartbeatCommand($owner, $bridge->id, [], 'b4e39aa7', hostSamples: [
            self::sample('2026-01-01 10:01:00'),
            self::sample('2026-01-01 10:20:00'),
        ]));

        self::assertEquals(
            [(string) $inside->id => 1000, (string) $outside->id => 1],
            $em->getConnection()->fetchAllKeyValue('SELECT run_id, peak_mem_bytes FROM bridge_worker_run_facts'),
        );
    }

    private static function sample(string $at): BridgeHostSampleReport
    {
        return new BridgeHostSampleReport(new \DateTimeImmutable($at, new \DateTimeZone('UTC')), [10.0, 30.0], 1000, 4000, 0, null, true);
    }

    private function storeHostSampling(string $value): void
    {
        $connection = $this->em()->getConnection();
        $connection->executeStatement('DELETE FROM feature_flag WHERE name = ?', [HostSampling::ENABLED_FLAG]);
        $connection->executeStatement(
            "INSERT INTO feature_flag (name, type, value, tags, options) VALUES (?, 'bool', ?, '[]', NULL)",
            [HostSampling::ENABLED_FLAG, $value],
        );
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
