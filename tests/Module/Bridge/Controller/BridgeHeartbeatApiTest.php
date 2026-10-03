<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Controller\Api\BridgeHookInput;
use App\Module\Bridge\Controller\Api\RecordBridgeHeartbeatRequest;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Service\BridgeCommandPayload;
use App\Module\Bridge\Service\WorkRequestPayload;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\CliInstallMethod;
use App\Module\Bridge\ValueObject\CliUpdateState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Outbox\AgentPush;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\AgentCredential;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

final class BridgeHeartbeatApiTest extends WebTestCase
{
    use BridgeScenario;

    private const string WORK_NOW = '2026-10-01 12:30:00';

    public function test_the_first_heartbeat_records_the_bridge_for_the_token_owner(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('clock', new MockClock('2026-09-14 16:00:00'));
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-create@example.com');
        $project = $this->project($em, $owner, 'Heartbeat App');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, [
            'projects' => [(string) $project->id],
            'cliVersion' => 'b4e39aa7',
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertJsonStringEqualsJsonString('{"cliRange":"^1.0","paused":false,"commands":[],"workRequests":[],"lostClaims":[]}', (string) $client->getResponse()->getContent());
        $bridge = $this->bridge($owner, $bridgeId);
        self::assertSame([(string) $project->id], $bridge->projects);
        self::assertNull($bridge->pausedReported);
        self::assertNull($bridge->capabilities);
        self::assertSame('b4e39aa7', $bridge->cliVersion);
        self::assertSame('2026-09-14 16:00:00', $bridge->lastSeenAt->format('Y-m-d H:i:s'));
        self::assertNull($bridge->updateState);
        self::assertNull($bridge->updateVersion);
    }

    public function test_the_update_report_is_stored(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-update@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, [
            'projects' => [],
            'cliVersion' => '1.2.0',
            'update' => ['state' => 'rolled-back', 'version' => '1.3.0', 'reason' => 'ignored'],
            'extra' => 'ignored',
        ]);

        self::assertResponseStatusCodeSame(200);
        $bridge = $this->bridge($owner, $bridgeId);
        self::assertSame(CliUpdateState::RolledBack, $bridge->updateState);
        self::assertSame('1.3.0', $bridge->updateVersion);
        self::assertNull($bridge->installMethod);
    }

    public function test_the_install_method_is_stored(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-install@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, [
            'projects' => [],
            'cliVersion' => '1.2.0',
            'update' => ['state' => 'off', 'version' => '1.3.0', 'install' => 'homebrew'],
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(CliInstallMethod::Homebrew, $this->bridge($owner, $bridgeId)->installMethod);
    }

    /** A newer CLI may name a method this server does not know, and its heartbeat must still land. */
    public function test_an_unknown_install_method_is_stored_as_null(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-install-unknown@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, [
            'projects' => [],
            'cliVersion' => '1.2.0',
            'update' => ['state' => 'current', 'install' => 'snap'],
        ]);

        self::assertResponseStatusCodeSame(200);
        $bridge = $this->bridge($owner, $bridgeId);
        self::assertSame(CliUpdateState::Current, $bridge->updateState);
        self::assertNull($bridge->installMethod);
    }

    /** The row holds current state, so a heartbeat with no update report clears the last one. */
    public function test_a_heartbeat_without_an_update_report_clears_the_stored_one(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-update-clear@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => '1.2.0', 'update' => ['state' => 'updating', 'version' => '1.3.0']]);
        self::assertSame(CliUpdateState::Updating, $this->bridge($owner, $bridgeId)->updateState);

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => '1.3.0']);

        self::assertResponseStatusCodeSame(200);
        $bridge = $this->bridge($owner, $bridgeId);
        self::assertNull($bridge->updateState);
        self::assertNull($bridge->updateVersion);
    }

    /** The row holds current state, so a later heartbeat replaces every field and writes no second row. */
    public function test_a_later_heartbeat_replaces_the_row(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('clock', new MockClock('2026-09-14 16:01:30'));
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-replace@example.com');
        $first = $this->project($em, $owner, 'First Heartbeat');
        $second = $this->project($em, $owner, 'Second Heartbeat');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();
        $this->seedBridge($em, $owner, Uuid::fromString($bridgeId), [(string) $first->id], 'old', new \DateTimeImmutable('2026-09-14 16:00:30'));

        $this->put($client, $bridgeId, $raw, [
            'projects' => [(string) $second->id],
            'cliVersion' => 'new (dirty)',
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->countBridges());
        $bridge = $this->bridge($owner, $bridgeId);
        self::assertSame([(string) $second->id], $bridge->projects);
        self::assertSame('new (dirty)', $bridge->cliVersion);
        self::assertSame('2026-09-14 16:01:30', $bridge->lastSeenAt->format('Y-m-d H:i:s'));
    }

    /**
     * Two accounts that share one config directory send one bridge id, and
     * each keeps a row of its own. Neither heartbeat touches the other's row.
     */
    public function test_a_second_account_with_the_same_bridge_id_keeps_its_own_row(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $first = $this->user($em, 'heartbeat-first-account@example.com');
        $second = $this->user($em, 'heartbeat-second-account@example.com');
        $secondProject = $this->project($em, $second, 'Second Account Heartbeat');
        $raw = $this->agentToken($client, $second);
        $bridgeId = Uuid::v4();
        $seenAt = new \DateTimeImmutable('2026-01-01 10:00:00');
        $this->seedBridge($em, $first, $bridgeId, [], 'first', $seenAt);

        $this->put($client, (string) $bridgeId, $raw, [
            'projects' => [(string) $secondProject->id],
            'cliVersion' => 'second',
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(2, $this->countBridges());
        $secondRow = $this->bridge($second, (string) $bridgeId);
        self::assertSame([(string) $secondProject->id], $secondRow->projects);
        self::assertSame('second', $secondRow->cliVersion);
        $firstRow = $this->bridge($first, (string) $bridgeId);
        self::assertSame([], $firstRow->projects);
        self::assertSame('first', $firstRow->cliVersion);
        self::assertEquals($seenAt, $firstRow->lastSeenAt);
    }

    /**
     * A project deleted while the bridge runs stays in its list until a restart,
     * so refusing the whole heartbeat would make a live bridge read as quiet.
     */
    public function test_a_project_the_caller_does_not_own_is_left_out_of_the_row(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-filter@example.com');
        $stranger = $this->user($em, 'heartbeat-stranger@example.com');
        $own = $this->project($em, $owner, 'Own Heartbeat');
        $foreign = $this->project($em, $stranger, 'Foreign Heartbeat');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, [
            'projects' => [(string) Uuid::v7(), (string) $own->id, (string) $foreign->id, strtoupper((string) $own->id)],
            'cliVersion' => 'b4e39aa7',
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([(string) $own->id], $this->bridge($owner, $bridgeId)->projects);
    }

    public function test_a_bridge_that_follows_no_project_is_recorded_with_an_empty_list(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-empty@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->bridge($owner, $bridgeId)->projects);
    }

    public function test_the_hook_report_is_stored_with_each_time_in_one_format(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-hooks@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'hooks' => [
            self::hook(),
            self::hook(['event' => 'stop', 'lastRunAt' => null, 'outcome' => 'never']),
            self::hook(['event' => 'busy', 'lastRunAt' => '2026-09-14T18:00:00+02:00', 'outcome' => 'timeout', 'error' => '  ']),
            self::hook(['event' => 'idle', 'lastRunAt' => '2026-09-14t16:00:00z', 'outcome' => 'failed', 'error' => 'exit 1']),
        ]]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            ['package' => 'github:acme/loupe-hooks', 'ref' => 'v1.2.0', 'event' => 'start', 'lastRunAt' => '2026-09-14T16:00:00+00:00', 'outcome' => 'ok', 'error' => null],
            ['package' => 'github:acme/loupe-hooks', 'ref' => 'v1.2.0', 'event' => 'stop', 'lastRunAt' => null, 'outcome' => 'never', 'error' => null],
            ['package' => 'github:acme/loupe-hooks', 'ref' => 'v1.2.0', 'event' => 'busy', 'lastRunAt' => '2026-09-14T18:00:00+02:00', 'outcome' => 'timeout', 'error' => null],
            ['package' => 'github:acme/loupe-hooks', 'ref' => 'v1.2.0', 'event' => 'idle', 'lastRunAt' => '2026-09-14T16:00:00+00:00', 'outcome' => 'failed', 'error' => 'exit 1'],
        ], $this->bridge($owner, $bridgeId)->hooks);
    }

    /** A bridge from before hooks sends no report, so its heartbeat leaves the stored rows alone. */
    public function test_a_heartbeat_without_hooks_keeps_the_stored_report(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-hooks-absent@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'hooks' => [self::hook()]]);
        self::assertResponseStatusCodeSame(200);
        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7']);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $this->bridge($owner, $bridgeId)->hooks);
    }

    public function test_the_worker_pools_are_stored(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-pools@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'workerPools' => [
            self::pool(),
            self::pool(['name' => 'quick-2', 'size' => 1, 'inUse' => 1, 'queued' => 1000]),
        ]]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            ['name' => 'default', 'size' => 3, 'inUse' => 2, 'queued' => 5],
            ['name' => 'quick-2', 'size' => 1, 'inUse' => 1, 'queued' => 1000],
        ], $this->bridge($owner, $bridgeId)->workerPools);
    }

    /** A bridge from before worker pools sends none, so its heartbeat leaves the stored rows alone. */
    public function test_a_heartbeat_without_worker_pools_keeps_the_stored_rows(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-pools-absent@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'workerPools' => [self::pool()]]);
        self::assertResponseStatusCodeSame(200);
        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'workerPools' => null]);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $this->bridge($owner, $bridgeId)->workerPools ?? []);
    }

    public function test_the_pause_state_and_the_capabilities_are_stored(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-capabilities@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'paused' => true, 'capabilities' => ['commands', 'pause']]);

        self::assertResponseStatusCodeSame(200);
        $bridge = $this->bridge($owner, $bridgeId);
        self::assertTrue($bridge->pausedReported);
        self::assertSame(['commands', 'pause'], $bridge->capabilities);
        self::assertTrue($bridge->takesCommands());
    }

    /** A bridge from before commands sends neither field, so its heartbeat leaves the stored values alone. */
    public function test_a_heartbeat_without_the_pause_state_or_the_capabilities_keeps_the_stored_values(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-capabilities-absent@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'paused' => false, 'capabilities' => ['commands']]);
        self::assertResponseStatusCodeSame(200);
        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'paused' => null, 'capabilities' => null]);

        self::assertResponseStatusCodeSame(200);
        $bridge = $this->bridge($owner, $bridgeId);
        self::assertFalse($bridge->pausedReported);
        self::assertSame(['commands'], $bridge->capabilities);
    }

    /**
     * The reply carries the pause a person asked for, and the commands this
     * bridge of this owner has still to act on, oldest first.
     */
    public function test_the_reply_carries_the_pause_request_and_the_pending_commands(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('clock', new MockClock('2026-09-29 12:10:00'));
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-commands@example.com');
        $stranger = $this->user($em, 'heartbeat-commands-stranger@example.com');
        $project = $this->project($em, $owner, 'Heartbeat Commands');
        $foreign = $this->project($em, $stranger, 'Heartbeat Commands Foreign');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = Uuid::v4();
        $bridge = $this->seedBridge($em, $owner, $bridgeId);
        $bridge->pauseRequested = true;
        $em->flush();

        $later = $this->seedCommand($em, $this->seedRun($em, $project, bridgeId: $bridgeId, runKey: Uuid::v4()), requestedAt: new \DateTimeImmutable('2026-09-29 12:05:00'));
        $earlier = $this->seedCommand($em, $this->seedRun($em, $project, bridgeId: $bridgeId), requestedAt: new \DateTimeImmutable('2026-09-29 12:00:00'), kind: BridgeCommandKind::ResumeRun);
        $this->seedCommand($em, $this->seedRun($em, $project, bridgeId: $bridgeId), requestedAt: new \DateTimeImmutable('2026-09-29 11:00:00'));
        $this->seedCommand($em, $this->seedRun($em, $project, bridgeId: $bridgeId), state: BridgeCommandState::Done);
        $this->seedCommand($em, $this->seedRun($em, $project, bridgeId: Uuid::v4()));
        $this->seedCommand($em, $this->seedRun($em, $foreign, bridgeId: $bridgeId));

        $this->put($client, (string) $bridgeId, $raw, ['projects' => [(string) $project->id], 'cliVersion' => 'b4e39aa7']);

        self::assertResponseStatusCodeSame(200);
        $reply = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($reply);
        self::assertTrue($reply['paused']);
        self::assertSame([(string) $earlier->id, (string) $later->id], array_column($reply['commands'], 'commandId'));
        self::assertSame(BridgeCommandPayload::of($later), $reply['commands'][1]);
        self::assertSame('resume-run', $reply['commands'][0]['kind']);
    }

    public function test_a_bridge_that_takes_work_requests_gets_the_open_requests_of_the_projects_it_follows(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('clock', new MockClock(self::WORK_NOW));
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-offers@example.com');
        $stranger = $this->user($em, 'heartbeat-offers-stranger@example.com');
        $followed = $this->project($em, $owner, 'Offers Followed');
        $unfollowed = $this->project($em, $owner, 'Offers Unfollowed');
        $foreign = $this->project($em, $stranger, 'Offers Foreign');
        $later = $this->seedWorkRequest($em, $followed, createdAt: new \DateTimeImmutable('2026-10-01 12:10:00'));
        $earlier = $this->seedWorkRequest($em, $followed, createdAt: new \DateTimeImmutable('2026-10-01 12:00:00'));
        $this->seedWorkRequest($em, $followed, state: WorkRequestState::Claimed, bridgeId: Uuid::v4(), claimToken: Uuid::v4(), leaseUntil: new \DateTimeImmutable('2026-10-01 12:31:00'));
        $this->seedWorkRequest($em, $followed, state: WorkRequestState::Done);
        $this->seedWorkRequest($em, $unfollowed);
        $this->seedWorkRequest($em, $foreign);

        $this->put($client, (string) Uuid::v4(), $this->agentToken($client, $owner), [
            'projects' => [(string) $followed->id, (string) $foreign->id],
            'cliVersion' => 'b4e39aa7',
            'capabilities' => [Bridge::CAPABILITY_WORK_REQUESTS],
        ]);

        self::assertResponseStatusCodeSame(200);
        $reply = $this->reply($client);
        self::assertSame([(string) $earlier->id, (string) $later->id], array_column($reply['workRequests'], 'workRequestId'));
        self::assertSame(WorkRequestPayload::of($earlier), $reply['workRequests'][0]);
        self::assertSame([], $reply['lostClaims']);
    }

    public function test_a_request_that_needs_a_capability_reaches_only_a_bridge_that_reports_it(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('clock', new MockClock(self::WORK_NOW));
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-offers-capability@example.com');
        $project = $this->project($em, $owner, 'Offers Capability');
        $any = $this->seedWorkRequest($em, $project, createdAt: new \DateTimeImmutable('2026-10-01 12:00:00'));
        $interactive = $this->seedWorkRequest($em, $project, kind: 'design', capability: Bridge::CAPABILITY_INTERACTIVE, createdAt: new \DateTimeImmutable('2026-10-01 12:05:00'));
        $raw = $this->agentToken($client, $owner);

        $this->put($client, (string) Uuid::v4(), $raw, [
            'projects' => [(string) $project->id],
            'cliVersion' => 'b4e39aa7',
            'capabilities' => [Bridge::CAPABILITY_WORK_REQUESTS],
        ]);
        self::assertSame([(string) $any->id], array_column($this->reply($client)['workRequests'], 'workRequestId'));

        $this->put($client, (string) Uuid::v4(), $raw, [
            'projects' => [(string) $project->id],
            'cliVersion' => 'b4e39aa7',
            'capabilities' => [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_INTERACTIVE],
        ]);
        self::assertSame([(string) $any->id, (string) $interactive->id], array_column($this->reply($client)['workRequests'], 'workRequestId'));
    }

    public function test_a_bridge_that_takes_no_work_requests_gets_no_offer(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-offers-none@example.com');
        $project = $this->project($em, $owner, 'Offers None');
        $this->seedWorkRequest($em, $project);

        $this->put($client, (string) Uuid::v4(), $this->agentToken($client, $owner), [
            'projects' => [(string) $project->id],
            'cliVersion' => 'b4e39aa7',
            'capabilities' => ['commands', Bridge::CAPABILITY_INTERACTIVE],
        ]);

        self::assertResponseStatusCodeSame(200);
        $reply = $this->reply($client);
        self::assertSame([], $reply['workRequests']);
        self::assertSame([], $reply['lostClaims']);
    }

    public function test_a_named_claim_that_the_bridge_holds_renews_its_lease(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('clock', new MockClock(self::WORK_NOW));
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-renew@example.com');
        $project = $this->project($em, $owner, 'Renew');
        $bridgeId = Uuid::v4();
        $token = Uuid::v4();
        $held = $this->seedWorkRequest($em, $project, state: WorkRequestState::Claimed, bridgeId: $bridgeId, claimToken: $token, leaseUntil: new \DateTimeImmutable('2026-10-01 12:31:00'));

        $this->put($client, (string) $bridgeId, $this->agentToken($client, $owner), [
            'projects' => [(string) $project->id],
            'cliVersion' => 'b4e39aa7',
            'capabilities' => [Bridge::CAPABILITY_WORK_REQUESTS],
            'workClaims' => [
                ['id' => (string) $held->id, 'claimToken' => (string) $token],
                ['id' => strtoupper((string) $held->id), 'claimToken' => (string) $token],
            ],
        ]);

        self::assertResponseStatusCodeSame(200);
        $reply = $this->reply($client);
        self::assertSame([], $reply['lostClaims']);
        self::assertSame([], $reply['workRequests']);
        self::assertSame('2026-10-01 12:32:00', $this->leaseUntil($held));
    }

    public function test_a_named_claim_that_the_bridge_no_longer_holds_is_lost(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('clock', new MockClock(self::WORK_NOW));
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-lost@example.com');
        $project = $this->project($em, $owner, 'Lost');
        $bridgeId = Uuid::v4();
        $token = Uuid::v4();
        $lease = new \DateTimeImmutable('2026-10-01 12:31:00');
        $held = $this->seedWorkRequest($em, $project, state: WorkRequestState::Claimed, bridgeId: $bridgeId, claimToken: $token, leaseUntil: $lease);
        $otherToken = $this->seedWorkRequest($em, $project, state: WorkRequestState::Claimed, bridgeId: $bridgeId, claimToken: Uuid::v4(), leaseUntil: $lease);
        $reopened = $this->seedWorkRequest($em, $project, createdAt: new \DateTimeImmutable('2026-10-01 12:20:00'));
        $withdrawn = $this->seedWorkRequest($em, $project, state: WorkRequestState::Cancelled, bridgeId: $bridgeId, claimToken: $token, leaseUntil: $lease);
        $unknown = Uuid::v4();

        $this->put($client, (string) $bridgeId, $this->agentToken($client, $owner), [
            'projects' => [(string) $project->id],
            'cliVersion' => 'b4e39aa7',
            'workClaims' => [
                ['id' => (string) $otherToken->id, 'claimToken' => (string) $token],
                ['id' => (string) $held->id, 'claimToken' => (string) $token],
                ['id' => (string) $reopened->id, 'claimToken' => (string) $token],
                ['id' => (string) $withdrawn->id, 'claimToken' => (string) $token],
                ['id' => (string) $unknown, 'claimToken' => (string) $token],
            ],
        ]);

        self::assertResponseStatusCodeSame(200);
        $reply = $this->reply($client);
        self::assertSame(
            [(string) $otherToken->id, (string) $reopened->id, (string) $withdrawn->id, (string) $unknown],
            $reply['lostClaims'],
        );
        self::assertSame([], $reply['workRequests']);
        self::assertSame('2026-10-01 12:32:00', $this->leaseUntil($held));
        self::assertSame('2026-10-01 12:31:00', $this->leaseUntil($otherToken));
    }

    public function test_a_claim_of_another_bridge_is_lost_and_keeps_its_lease(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('clock', new MockClock(self::WORK_NOW));
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-lost-bridge@example.com');
        $project = $this->project($em, $owner, 'Lost Bridge');
        $token = Uuid::v4();
        $other = $this->seedWorkRequest($em, $project, state: WorkRequestState::Claimed, bridgeId: Uuid::v4(), claimToken: $token, leaseUntil: new \DateTimeImmutable('2026-10-01 12:31:00'));

        $this->put($client, (string) Uuid::v4(), $this->agentToken($client, $owner), [
            'projects' => [(string) $project->id],
            'cliVersion' => 'b4e39aa7',
            'workClaims' => [['id' => (string) $other->id, 'claimToken' => (string) $token]],
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([(string) $other->id], $this->reply($client)['lostClaims']);
        self::assertSame('2026-10-01 12:31:00', $this->leaseUntil($other));
    }

    /** A bridge that predates work requests sends no list, and its claims, if any, run out. */
    public function test_a_heartbeat_without_work_claims_renews_no_lease(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('clock', new MockClock(self::WORK_NOW));
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-no-claims@example.com');
        $project = $this->project($em, $owner, 'No Claims');
        $bridgeId = Uuid::v4();
        $held = $this->seedWorkRequest($em, $project, state: WorkRequestState::Claimed, bridgeId: $bridgeId, claimToken: Uuid::v4(), leaseUntil: new \DateTimeImmutable('2026-10-01 12:31:00'));

        $this->put($client, (string) $bridgeId, $this->agentToken($client, $owner), [
            'projects' => [(string) $project->id],
            'cliVersion' => 'b4e39aa7',
            'capabilities' => [Bridge::CAPABILITY_WORK_REQUESTS],
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->reply($client)['lostClaims']);
        self::assertSame('2026-10-01 12:31:00', $this->leaseUntil($held));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function workClaim(array $overrides = []): array
    {
        return array_merge(['id' => (string) Uuid::v4(), 'claimToken' => (string) Uuid::v4()], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function pool(array $overrides = []): array
    {
        return array_merge(['name' => 'default', 'size' => 3, 'inUse' => 2, 'queued' => 5], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function hook(array $overrides = []): array
    {
        return array_merge([
            'package' => 'github:acme/loupe-hooks',
            'ref' => 'v1.2.0',
            'event' => 'start',
            'lastRunAt' => '2026-09-14T16:00:00.000Z',
            'outcome' => 'ok',
            'error' => null,
        ], $overrides);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidPayloads(): iterable
    {
        yield 'hooks that are not a list' => [['hooks' => 'loupe']];
        yield 'hooks keyed by name' => [['hooks' => ['start' => self::hook()]]];
        yield 'a hook that is not an object' => [['hooks' => ['loupe']]];
        yield 'too many hooks' => [['hooks' => array_fill(0, RecordBridgeHeartbeatRequest::MAX_HOOKS + 1, self::hook())]];
        yield 'a hook with no package' => [['hooks' => [self::hook(['package' => null])]]];
        yield 'a hook package that is too long' => [['hooks' => [self::hook(['package' => str_repeat('a', BridgeHookInput::MAX_PACKAGE_LENGTH + 1)])]]];
        yield 'a hook with a blank ref' => [['hooks' => [self::hook(['ref' => ' '])]]];
        yield 'a hook ref that is too long' => [['hooks' => [self::hook(['ref' => str_repeat('a', BridgeHookInput::MAX_REF_LENGTH + 1)])]]];
        yield 'an unknown hook event' => [['hooks' => [self::hook(['event' => 'deploy'])]]];
        yield 'an unknown hook outcome' => [['hooks' => [self::hook(['outcome' => 'crashed'])]]];
        yield 'a hook with no outcome' => [['hooks' => [self::hook(['outcome' => null])]]];
        yield 'a hook run time that is not a date' => [['hooks' => [self::hook(['lastRunAt' => 'yesterday-ish'])]]];
        yield 'a hook run time in words' => [['hooks' => [self::hook(['lastRunAt' => 'tomorrow'])]]];
        yield 'a hook run time on a day the month lacks' => [['hooks' => [self::hook(['lastRunAt' => '2026-02-31T16:00:00Z'])]]];
        yield 'a hook run time with no offset' => [['hooks' => [self::hook(['lastRunAt' => '2026-09-14T16:00:00'])]]];
        yield 'a hook error that is too long' => [['hooks' => [self::hook(['error' => str_repeat('a', BridgeHookInput::MAX_ERROR_LENGTH + 1)])]]];
        yield 'worker pools that are not a list' => [['workerPools' => 'default']];
        yield 'worker pools keyed by name' => [['workerPools' => ['default' => self::pool()]]];
        yield 'too many worker pools' => [['workerPools' => array_fill(0, RecordBridgeHeartbeatRequest::MAX_WORKER_POOLS + 1, self::pool())]];
        yield 'a worker pool with a bad name' => [['workerPools' => [self::pool(['name' => 'Quick Pool'])]]];
        yield 'a worker pool with no name' => [['workerPools' => [self::pool(['name' => null])]]];
        yield 'a worker pool name with a trailing newline' => [['workerPools' => [self::pool(['name' => "default\n"])]]];
        yield 'a worker pool with no size' => [['workerPools' => [self::pool(['size' => null])]]];
        yield 'a worker pool with a negative use' => [['workerPools' => [self::pool(['inUse' => -1])]]];
        yield 'a worker pool with a queue above the limit' => [['workerPools' => [self::pool(['queued' => 1001])]]];
        yield 'a worker pool size above the limit' => [['workerPools' => [self::pool(['size' => 1001])]]];
        yield 'a worker pool size as text' => [['workerPools' => [self::pool(['size' => 'three'])]]];
        yield 'capabilities that are not a list' => [['capabilities' => 'commands']];
        yield 'capabilities keyed by name' => [['capabilities' => ['commands' => 'commands']]];
        yield 'too many capabilities' => [['capabilities' => array_map(static fn (int $i): string => 'c'.$i, range(0, RecordBridgeHeartbeatRequest::MAX_CAPABILITIES))]];
        yield 'a capability with an upper-case name' => [['capabilities' => ['Commands']]];
        yield 'a capability name with a trailing newline' => [['capabilities' => ["commands\n"]]];
        yield 'a capability name that is too long' => [['capabilities' => [str_repeat('a', 41)]]];
        yield 'a capability that is not a string' => [['capabilities' => [7]]];
        yield 'a pause state as text' => [['paused' => 'yes']];
        yield 'work claims that are not a list' => [['workClaims' => 'claims']];
        yield 'work claims keyed by id' => [['workClaims' => ['first' => self::workClaim()]]];
        yield 'too many work claims' => [['workClaims' => array_fill(0, RecordBridgeHeartbeatRequest::MAX_WORK_CLAIMS + 1, self::workClaim())]];
        yield 'a work claim that is not an object' => [['workClaims' => ['claim']]];
        yield 'a work claim with no id' => [['workClaims' => [self::workClaim(['id' => null])]]];
        yield 'a work claim id that is not a uuid' => [['workClaims' => [self::workClaim(['id' => 'loupe'])]]];
        yield 'a work claim with no token' => [['workClaims' => [self::workClaim(['claimToken' => null])]]];
        yield 'a work claim token that is not a uuid' => [['workClaims' => [self::workClaim(['claimToken' => 'secret'])]]];
        yield 'no projects' => [['projects' => null]];
        yield 'projects that are not a list' => [['projects' => 'loupe']];
        yield 'a project that is not a uuid' => [['projects' => ['loupe']]];
        yield 'a blank project' => [['projects' => ['']]];
        yield 'too many projects' => [['projects' => array_map(
            static fn (): string => (string) Uuid::v7(),
            range(0, RecordBridgeHeartbeatRequest::MAX_PROJECTS),
        )]];
        yield 'no cli version' => [['cliVersion' => null]];
        yield 'a blank cli version' => [['cliVersion' => '   ']];
        yield 'a cli version that is too long' => [['cliVersion' => str_repeat('a', Bridge::MAX_CLI_VERSION_LENGTH + 1)]];
        yield 'an unknown update state' => [['update' => ['state' => 'exploded']]];
        yield 'an update with no state' => [['update' => ['version' => '1.3.0']]];
        yield 'an update version that is too long' => [['update' => ['state' => 'updating', 'version' => str_repeat('1', Bridge::MAX_UPDATE_VERSION_LENGTH + 1)]]];
        yield 'a name that is too long' => [['name' => str_repeat('a', Bridge::MAX_NAME_LENGTH + 1)]];
        yield 'a name with a control character' => [['name' => "lap\x01top"]];
        yield 'a name that is not a string' => [['name' => 7]];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidPayloads')]
    public function test_an_invalid_heartbeat_is_refused(array $overrides): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-invalid@example.com');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, (string) Uuid::v4(), $raw, array_merge(['projects' => [], 'cliVersion' => 'b4e39aa7'], $overrides));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->countBridges());
    }

    /** The version is trimmed before it is measured, so the stored value is the one the check read. */
    public function test_the_cli_version_is_stored_trimmed(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-trim@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => '  b4e39aa7  ']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('b4e39aa7', $this->bridge($owner, $bridgeId)->cliVersion);
    }

    /** The length is measured on the trimmed name, so the stored value is the one the check read. */
    public function test_the_name_is_stored_trimmed(): void
    {
        $client = static::createClient();
        $owner = $this->user($this->em(), 'heartbeat-name-trim@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'name' => '  '.str_repeat('a', Bridge::MAX_NAME_LENGTH).'  ']);

        self::assertResponseStatusCodeSame(200);
        $bridge = $this->bridge($owner, $bridgeId);
        self::assertSame(str_repeat('a', Bridge::MAX_NAME_LENGTH), $bridge->name);
        self::assertSame(str_repeat('a', Bridge::MAX_NAME_LENGTH), $bridge->requestedName);
    }

    /** The CLI refuses only control characters, and a refused name fails the whole heartbeat, so the server refuses no more. */
    public function test_a_name_with_a_format_character_is_accepted(): void
    {
        $client = static::createClient();
        $owner = $this->user($this->em(), 'heartbeat-name-format@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'name' => "lap\u{200D}top"]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame("lap\u{200D}top", $this->bridge($owner, $bridgeId)->name);
    }

    public function test_a_blank_name_clears_both_names(): void
    {
        $client = static::createClient();
        $owner = $this->user($this->em(), 'heartbeat-name-blank@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'name' => 'laptop']);
        self::assertSame('laptop', $this->bridge($owner, $bridgeId)->name);

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'name' => '   ']);

        self::assertResponseStatusCodeSame(200);
        $bridge = $this->bridge($owner, $bridgeId);
        self::assertNull($bridge->name);
        self::assertNull($bridge->requestedName);
    }

    public function test_a_heartbeat_without_a_name_keeps_the_stored_names(): void
    {
        $client = static::createClient();
        $owner = $this->user($this->em(), 'heartbeat-name-absent@example.com');
        $raw = $this->agentToken($client, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'name' => 'laptop']);
        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7']);

        self::assertResponseStatusCodeSame(200);
        $bridge = $this->bridge($owner, $bridgeId);
        self::assertSame('laptop', $bridge->name);
        self::assertSame('laptop', $bridge->requestedName);
    }

    public function test_a_name_another_bridge_holds_still_answers_the_heartbeat(): void
    {
        $client = static::createClient();
        $owner = $this->user($this->em(), 'heartbeat-name-clash-api@example.com');
        $raw = $this->agentToken($client, $owner);
        $holder = (string) Uuid::v4();
        $claimer = (string) Uuid::v4();

        $this->put($client, $holder, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'name' => 'laptop']);
        $this->put($client, $claimer, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7', 'name' => 'laptop']);

        self::assertResponseStatusCodeSame(200);
        $bridge = $this->bridge($owner, $claimer);
        self::assertNull($bridge->name);
        self::assertSame('laptop', $bridge->requestedName);
    }

    public function test_a_bridge_id_that_is_not_a_uuid_is_not_found(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $raw = $this->agentToken($client, $this->user($em, 'heartbeat-baduuid@example.com'));

        $this->put($client, 'not-a-uuid', $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->countBridges());
    }

    public function test_it_is_absent_while_push_is_switched_off(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $raw = $this->agentToken($client, $this->user($em, 'heartbeat-flag@example.com'));
        $em->getConnection()->executeStatement(
            "UPDATE feature_flag SET value = 'false' WHERE name = ?",
            [AgentPush::FLAG],
        );

        $this->put($client, (string) Uuid::v4(), $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->countBridges());
    }

    public function test_a_widget_token_is_refused_by_the_firewall(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-widget@example.com');
        $project = $this->project($em, $owner, 'Widget Heartbeat');
        $raw = AgentCredential::tokenFor(static::getContainer(), $owner, 'site-review', $project);

        $this->put($client, (string) Uuid::v4(), $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->countBridges());
    }

    public function test_an_mcp_token_is_refused_by_the_firewall(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-mcp@example.com');
        $project = $this->project($em, $owner, 'Heartbeat MCP');
        $raw = AgentCredential::tokenFor(static::getContainer(), $owner, 'mcp', $project);

        $this->put($client, (string) Uuid::v4(), $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->countBridges());
    }

    public function test_a_request_without_a_token_is_refused(): void
    {
        $client = static::createClient();

        $client->request(
            Request::METHOD_PUT,
            '/api/bridges/'.Uuid::v4().'/heartbeat',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['projects' => [], 'cliVersion' => 'b4e39aa7'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(401);
        self::assertSame(0, $this->countBridges());
    }

    /**
     * With the credential unresolved, the listener would key on the address,
     * and the second heartbeat below would pass. A 429 proves the firewall ran
     * first, and the third proves a second account keeps its own budget.
     */
    public function test_the_limit_counts_per_token_because_the_firewall_runs_first(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('limiter.agent_bridge_heartbeats', new RateLimiterFactory(
            ['id' => 'agent_bridge_heartbeats', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-limit@example.com');
        $other = $this->user($em, 'heartbeat-limit-other@example.com');
        $first = $this->agentToken($client, $owner);
        $second = $this->agentToken($client, $other);
        $body = ['projects' => [], 'cliVersion' => 'b4e39aa7'];

        $this->put($client, (string) Uuid::v4(), $first, $body, '203.0.113.7');
        self::assertResponseStatusCodeSame(200);

        $this->put($client, (string) Uuid::v4(), $first, $body, '198.51.100.4');
        self::assertResponseStatusCodeSame(429);

        $this->put($client, (string) Uuid::v4(), $second, $body, '203.0.113.7');
        self::assertResponseStatusCodeSame(200);
    }

    /** The shipped limiter takes 60 heartbeats from one token in a minute and refuses the 61st. */
    public function test_the_shipped_limit_refuses_the_sixty_first_heartbeat_of_a_token(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();
        $raw = $this->agentToken($client, $this->user($em, 'heartbeat-capacity@example.com'));
        $body = ['projects' => [], 'cliVersion' => 'b4e39aa7'];

        for ($heartbeat = 1; $heartbeat <= 60; ++$heartbeat) {
            $this->put($client, (string) Uuid::v4(), $raw, $body);
            self::assertResponseStatusCodeSame(200, 'heartbeat '.$heartbeat);
        }

        $this->put($client, (string) Uuid::v4(), $raw, $body);
        self::assertResponseStatusCodeSame(429);
    }

    /** The limiter runs before the payload is mapped, so a flood of invalid bodies still spends the budget. */
    public function test_the_limit_answers_before_the_body_is_validated(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('limiter.agent_bridge_heartbeats', new RateLimiterFactory(
            ['id' => 'agent_bridge_heartbeats', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
        $em = $this->em();
        $raw = $this->agentToken($client, $this->user($em, 'heartbeat-limit-order@example.com'));

        $this->put($client, (string) Uuid::v4(), $raw, ['projects' => 'loupe']);
        self::assertResponseStatusCodeSame(422);

        $this->put($client, (string) Uuid::v4(), $raw, ['projects' => 'loupe']);
        self::assertResponseStatusCodeSame(429);
        self::assertTrue($client->getResponse()->headers->has('Retry-After'));
    }

    /** @param array<string, mixed> $payload */
    private function put(KernelBrowser $client, string $bridgeId, string $raw, array $payload, string $clientIp = '127.0.0.1'): void
    {
        $client->request(
            Request::METHOD_PUT,
            '/api/bridges/'.$bridgeId.'/heartbeat',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'REMOTE_ADDR' => $clientIp,
            ],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    private function bridge(User $owner, string $id): Bridge
    {
        $em = $this->em();
        $em->clear();
        $bridges = static::getContainer()->get(BridgeRepository::class);
        self::assertInstanceOf(BridgeRepository::class, $bridges);
        $bridge = $bridges->findOneByOwnerAndId($owner, Uuid::fromString($id));
        self::assertInstanceOf(Bridge::class, $bridge);

        return $bridge;
    }

    /** @return array<mixed> */
    private function reply(KernelBrowser $client): array
    {
        $reply = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($reply);

        return $reply;
    }

    private function leaseUntil(WorkRequest $request): ?string
    {
        $em = $this->em();
        $em->clear();

        return $em->find(WorkRequest::class, $request->id)?->leaseUntil?->format('Y-m-d H:i:s');
    }

    private function countBridges(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridges');
    }
}
