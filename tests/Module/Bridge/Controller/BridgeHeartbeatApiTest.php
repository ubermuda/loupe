<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Bridge\Controller\Api\BridgeHeartbeatRequest;
use App\Module\Bridge\Entity\Bridge;
use App\Outbox\AgentPush;
use App\Tests\Module\Bridge\BridgeScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

final class BridgeHeartbeatApiTest extends WebTestCase
{
    use BridgeScenario;

    public function test_the_first_heartbeat_records_the_bridge_for_the_token_owner(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-create@example.com');
        $project = $this->project($em, $owner, 'Heartbeat App');
        $raw = $this->agentToken($em, $owner);
        $bridgeId = (string) Uuid::v4();
        $before = new \DateTimeImmutable('-1 minute');

        $this->put($client, $bridgeId, $raw, [
            'projects' => [(string) $project->id],
            'cliVersion' => 'b4e39aa7',
        ]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', (string) $client->getResponse()->getContent());
        $bridge = $this->bridge($bridgeId);
        self::assertSame((string) $owner->id, (string) $bridge->owner->id);
        self::assertSame([(string) $project->id], $bridge->projects);
        self::assertSame('b4e39aa7', $bridge->cliVersion);
        self::assertGreaterThan($before, $bridge->lastSeenAt);
    }

    /** The row holds current state, so a later heartbeat replaces every field and writes no second row. */
    public function test_a_later_heartbeat_replaces_the_row(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-replace@example.com');
        $first = $this->project($em, $owner, 'First Heartbeat');
        $second = $this->project($em, $owner, 'Second Heartbeat');
        $raw = $this->agentToken($em, $owner);
        $bridgeId = (string) Uuid::v4();
        $this->seedBridge($em, $owner, Uuid::fromString($bridgeId), [(string) $first->id], 'old', new \DateTimeImmutable('2026-01-01 10:00:00'));

        $this->put($client, $bridgeId, $raw, [
            'projects' => [(string) $second->id],
            'cliVersion' => 'new (dirty)',
        ]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame(1, $this->countBridges());
        $bridge = $this->bridge($bridgeId);
        self::assertSame([(string) $second->id], $bridge->projects);
        self::assertSame('new (dirty)', $bridge->cliVersion);
        self::assertGreaterThan(new \DateTimeImmutable('2026-01-02'), $bridge->lastSeenAt);
    }

    /**
     * A heartbeat answers with a bare acknowledgement, so a bridge id another
     * account holds must read exactly like one the server accepted.
     */
    public function test_a_bridge_id_another_account_holds_is_refused_in_silence(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $holder = $this->user($em, 'heartbeat-holder@example.com');
        $caller = $this->user($em, 'heartbeat-caller@example.com');
        $callerProject = $this->project($em, $caller, 'Caller Heartbeat');
        $raw = $this->agentToken($em, $caller);
        $bridgeId = Uuid::v4();
        $seenAt = new \DateTimeImmutable('2026-01-01 10:00:00');
        $this->seedBridge($em, $holder, $bridgeId, [], 'holder', $seenAt);

        $this->put($client, (string) $bridgeId, $raw, [
            'projects' => [(string) $callerProject->id],
            'cliVersion' => 'caller',
        ]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', (string) $client->getResponse()->getContent());
        $bridge = $this->bridge((string) $bridgeId);
        self::assertSame((string) $holder->id, (string) $bridge->owner->id);
        self::assertSame([], $bridge->projects);
        self::assertSame('holder', $bridge->cliVersion);
        self::assertEquals($seenAt, $bridge->lastSeenAt);
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
        $raw = $this->agentToken($em, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, [
            'projects' => [(string) Uuid::v7(), (string) $own->id, (string) $foreign->id, strtoupper((string) $own->id)],
            'cliVersion' => 'b4e39aa7',
        ]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame([(string) $own->id], $this->bridge($bridgeId)->projects);
    }

    public function test_a_bridge_that_follows_no_project_is_recorded_with_an_empty_list(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-empty@example.com');
        $raw = $this->agentToken($em, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7']);

        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->bridge($bridgeId)->projects);
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
            range(0, BridgeHeartbeatRequest::MAX_PROJECTS),
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
        $raw = $this->agentToken($em, $owner);

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
        $raw = $this->agentToken($em, $owner);
        $bridgeId = (string) Uuid::v4();

        $this->put($client, $bridgeId, $raw, ['projects' => [], 'cliVersion' => '  b4e39aa7  ']);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('b4e39aa7', $this->bridge($bridgeId)->cliVersion);
    }

    public function test_a_bridge_id_that_is_not_a_uuid_is_not_found(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $raw = $this->agentToken($em, $this->user($em, 'heartbeat-baduuid@example.com'));

        $this->put($client, 'not-a-uuid', $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->countBridges());
    }

    public function test_it_is_absent_while_push_is_switched_off(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $raw = $this->agentToken($em, $this->user($em, 'heartbeat-flag@example.com'));
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
        [$token, $raw] = ApiToken::issue($owner, 'widget', ApiTokenScope::SiteReview);
        $em->persist($token);
        $project->widgetToken = $token;
        $em->flush();

        $this->put($client, (string) Uuid::v4(), $raw, ['projects' => [], 'cliVersion' => 'b4e39aa7']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->countBridges());
    }

    public function test_an_mcp_token_is_refused_by_the_firewall(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-mcp@example.com');
        [$token, $raw] = ApiToken::issue($owner, 'mcp', ApiTokenScope::Mcp);
        $em->persist($token);
        $em->flush();

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
     * With the token unresolved, the listener would key on the address, and the
     * second heartbeat below would pass. A 429 proves the firewall ran first.
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
        $first = $this->agentToken($em, $owner);
        $second = $this->agentToken($em, $owner);
        $body = ['projects' => [], 'cliVersion' => 'b4e39aa7'];

        $this->put($client, (string) Uuid::v4(), $first, $body, '203.0.113.7');
        self::assertResponseStatusCodeSame(204);

        $this->put($client, (string) Uuid::v4(), $first, $body, '198.51.100.4');
        self::assertResponseStatusCodeSame(429);

        $this->put($client, (string) Uuid::v4(), $second, $body, '203.0.113.7');
        self::assertResponseStatusCodeSame(204);
    }

    /**
     * Ten bridges on one token, each posting once a minute at the default
     * interval, stay far inside the shipped limit, and a runaway loop does not.
     */
    public function test_the_shipped_limit_allows_sixty_heartbeats_a_minute_per_token(): void
    {
        static::createClient();
        $factory = static::getContainer()->get('limiter.agent_bridge_heartbeats');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $factory);
        $limiter = $factory->create('heartbeat-capacity-'.Uuid::v4());

        self::assertTrue($limiter->consume(60)->isAccepted());
        self::assertFalse($limiter->consume()->isAccepted());
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

    private function bridge(string $id): Bridge
    {
        $em = $this->em();
        $em->clear();
        $bridge = $em->find(Bridge::class, Uuid::fromString($id));
        self::assertInstanceOf(Bridge::class, $bridge);

        return $bridge;
    }

    private function countBridges(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridges');
    }
}
