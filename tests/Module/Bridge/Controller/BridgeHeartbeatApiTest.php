<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Controller\Api\RecordBridgeHeartbeatRequest;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Repository\BridgeRepository;
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

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', (string) $client->getResponse()->getContent());
        $bridge = $this->bridge($owner, $bridgeId);
        self::assertSame([(string) $project->id], $bridge->projects);
        self::assertSame('b4e39aa7', $bridge->cliVersion);
        self::assertSame('2026-09-14 16:00:00', $bridge->lastSeenAt->format('Y-m-d H:i:s'));
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

        self::assertResponseStatusCodeSame(204);
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

        self::assertResponseStatusCodeSame(204);
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

        self::assertResponseStatusCodeSame(204);
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

        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->bridge($owner, $bridgeId)->projects);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidPayloads(): iterable
    {
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

        self::assertResponseStatusCodeSame(204);
        self::assertSame('b4e39aa7', $this->bridge($owner, $bridgeId)->cliVersion);
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
        self::assertResponseStatusCodeSame(204);

        $this->put($client, (string) Uuid::v4(), $first, $body, '198.51.100.4');
        self::assertResponseStatusCodeSame(429);

        $this->put($client, (string) Uuid::v4(), $second, $body, '203.0.113.7');
        self::assertResponseStatusCodeSame(204);
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
            self::assertResponseStatusCodeSame(204, 'heartbeat '.$heartbeat);
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

    private function countBridges(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridges');
    }
}
