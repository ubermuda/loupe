<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Account\Entity\User;
use App\Module\Board\Repository\BridgeRuleReportRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Support\AgentCredential;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

final class BridgeRuleReportApiTest extends WebTestCase
{
    use BoardScenario;

    private const array DEAD_RULE = ['name' => 'plan', 'on' => 'board.card_moved', 'columns' => ['ready'], 'state' => 'dead', 'reason' => 'column_renamed'];
    private const array LIVE_RULE = ['name' => 'review', 'on' => 'board.card_moved', 'columns' => ['review', 'ready'], 'state' => 'live', 'reason' => null];

    public function test_it_stores_the_report_and_answers_no_content(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'rules-api-store@example.com');
        $project = $this->project($em, $owner, 'Rules App');
        $raw = $this->agentToken($client, $owner);
        $this->enableBoard();
        $bridge = (string) Uuid::v4();

        $this->put($client, $this->path((string) $project->id, $bridge), $raw, ['rules' => [self::DEAD_RULE, self::LIVE_RULE]]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', (string) $client->getResponse()->getContent());
        self::assertSame([[self::DEAD_RULE, self::LIVE_RULE]], $this->storedRules($em, $project, $bridge));
    }

    public function test_the_handle_can_be_the_project_slug(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'rules-api-slug@example.com');
        $project = $this->project($em, $owner, 'Slug Rules App');
        $raw = $this->agentToken($client, $owner);
        $this->enableBoard();
        $bridge = (string) Uuid::v4();

        $this->put($client, $this->path('slug-rules-app', $bridge), $raw, ['rules' => [self::LIVE_RULE]]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame([[self::LIVE_RULE]], $this->storedRules($em, $project, $bridge));
    }

    public function test_a_second_report_from_the_same_bridge_replaces_the_first(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $clock = new MockClock('2026-09-13 10:00:00');
        static::getContainer()->set('clock', $clock);
        $em = $this->em();
        $owner = $this->user($em, 'rules-api-replace@example.com');
        $project = $this->project($em, $owner, 'Replace App');
        $raw = $this->agentToken($client, $owner);
        $this->enableBoard();
        $bridge = (string) Uuid::v4();
        $path = $this->path((string) $project->id, $bridge);

        $this->put($client, $path, $raw, ['rules' => [self::DEAD_RULE, self::LIVE_RULE]]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('2026-09-13 10:00:00', $this->receivedAt($em, $project, $bridge));

        $clock->sleep(90);
        $this->put($client, $path, $raw, ['rules' => [self::LIVE_RULE]]);
        self::assertResponseStatusCodeSame(204);

        self::assertSame([[self::LIVE_RULE]], $this->storedRules($em, $project, $bridge));
        self::assertSame('2026-09-13 10:01:30', $this->receivedAt($em, $project, $bridge));

        $this->put($client, $path, $raw, ['rules' => []]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame([[]], $this->storedRules($em, $project, $bridge));
    }

    public function test_another_bridge_keeps_its_own_report(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'rules-api-bridges@example.com');
        $project = $this->project($em, $owner, 'Two Bridges App');
        $raw = $this->agentToken($client, $owner);
        $this->enableBoard();
        $first = (string) Uuid::v4();
        $second = (string) Uuid::v4();

        $this->put($client, $this->path((string) $project->id, $first), $raw, ['rules' => [self::DEAD_RULE]]);
        $this->put($client, $this->path((string) $project->id, $second), $raw, ['rules' => [self::LIVE_RULE]]);

        self::assertSame([[self::DEAD_RULE]], $this->storedRules($em, $project, $first));
        self::assertSame([[self::LIVE_RULE]], $this->storedRules($em, $project, $second));
    }

    /** A bridge that loses its id reports under a new one at each start, so a project keeps only its newest reports. */
    public function test_a_project_keeps_only_its_newest_reports(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $clock = new MockClock('2026-09-13 10:00:00');
        static::getContainer()->set('clock', $clock);
        $em = $this->em();
        $owner = $this->user($em, 'rules-api-cap@example.com');
        $project = $this->project($em, $owner, 'Capped App');
        $other = $this->project($em, $owner, 'Uncapped App');
        $raw = $this->agentToken($client, $owner);
        $this->enableBoard();

        $this->put($client, $this->path((string) $other->id, (string) Uuid::v4()), $raw, ['rules' => [self::LIVE_RULE]]);
        $bridges = [];
        for ($i = 0; $i < BridgeRuleReportRepository::KEPT_PER_PROJECT + 2; ++$i) {
            $clock->sleep(60);
            $bridges[] = $bridge = (string) Uuid::v4();
            $this->put($client, $this->path((string) $project->id, $bridge), $raw, ['rules' => [self::LIVE_RULE]]);
            self::assertResponseStatusCodeSame(204);
        }

        $count = static fn (Project $p): int => (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM board_bridge_rule_reports WHERE project_id = :project', ['project' => (string) $p->id]);
        self::assertSame(BridgeRuleReportRepository::KEPT_PER_PROJECT, $count($project));
        self::assertSame([], $this->storedRules($em, $project, $bridges[0]));
        self::assertSame([], $this->storedRules($em, $project, $bridges[1]));
        self::assertSame([[self::LIVE_RULE]], $this->storedRules($em, $project, $bridges[2]));
        self::assertSame(1, $count($other));

        // A replace makes the oldest report the newest, so it survives the next prune.
        $clock->sleep(60);
        $this->put($client, $this->path((string) $project->id, $bridges[2]), $raw, ['rules' => [self::DEAD_RULE]]);
        $clock->sleep(60);
        $this->put($client, $this->path((string) $project->id, (string) Uuid::v4()), $raw, ['rules' => []]);
        self::assertSame([[self::DEAD_RULE]], $this->storedRules($em, $project, $bridges[2]));
        self::assertSame([], $this->storedRules($em, $project, $bridges[3]));
    }

    public function test_a_column_slug_as_long_as_a_column_can_derive_is_accepted(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'rules-api-long-slug@example.com');
        $project = $this->project($em, $owner, 'Long Slug App');
        $raw = $this->agentToken($client, $owner);
        $this->enableBoard();
        $bridge = (string) Uuid::v4();
        $slug = rtrim(str_repeat('ab-', 600), '-');
        self::assertGreaterThan(1700, \strlen($slug));

        $this->put($client, $this->path((string) $project->id, $bridge), $raw, ['rules' => [['columns' => [$slug]] + self::LIVE_RULE]]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame([[array_replace(self::LIVE_RULE, ['columns' => [$slug]])]], $this->storedRules($em, $project, $bridge));
    }

    public function test_the_board_switched_off_answers_before_the_body_is_validated(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'rules-api-flag-invalid@example.com');
        $project = $this->project($em, $owner, 'Flag Invalid App');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $this->path((string) $project->id, (string) Uuid::v4()), $raw, ['rules' => 'not a list']);

        self::assertResponseStatusCodeSame(404);
        self::assertJsonStringEqualsJsonString('{"error":"board_disabled"}', (string) $client->getResponse()->getContent());
    }

    /** The payload has no prompt field, so a prompt the bridge sends never reaches the database. */
    public function test_a_prompt_in_the_body_is_not_stored(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'rules-api-prompt@example.com');
        $project = $this->project($em, $owner, 'Prompt App');
        $raw = $this->agentToken($client, $owner);
        $this->enableBoard();
        $bridge = (string) Uuid::v4();

        $this->put($client, $this->path((string) $project->id, $bridge), $raw, [
            'prompt' => 'Top-level secret prompt',
            'rules' => [self::LIVE_RULE + ['prompt' => 'Write a plan for card {cardId}']],
        ]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame([[self::LIVE_RULE]], $this->storedRules($em, $project, $bridge));
        $json = (string) $em->getConnection()->fetchOne(
            'SELECT rules::text FROM board_bridge_rule_reports WHERE project_id = :project',
            ['project' => (string) $project->id],
        );
        self::assertStringNotContainsString('prompt', $json);
        self::assertStringNotContainsString('Write a plan', $json);
    }

    public function test_another_users_project_is_indistinguishable_from_an_unknown_one(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $caller = $this->user($em, 'rules-api-caller@example.com');
        $other = $this->project($em, $this->user($em, 'rules-api-other@example.com'), 'Private Rules App');
        $raw = $this->agentToken($client, $caller);
        $this->enableBoard();

        foreach ([(string) $other->id, 'private-rules-app', (string) Uuid::v7(), 'Private Rules App'] as $handle) {
            $this->put($client, $this->path(rawurlencode($handle), (string) Uuid::v4()), $raw, ['rules' => [self::DEAD_RULE]]);

            self::assertResponseStatusCodeSame(404);
            self::assertJsonStringEqualsJsonString('{"error":"project_not_found"}', (string) $client->getResponse()->getContent(), $handle);
        }

        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM board_bridge_rule_reports WHERE project_id = :project', ['project' => (string) $other->id]));
    }

    public function test_it_is_absent_while_the_board_is_switched_off(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'rules-api-flag@example.com');
        $project = $this->project($em, $owner, 'Flag Rules App');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $this->path((string) $project->id, (string) Uuid::v4()), $raw, ['rules' => [self::LIVE_RULE]]);

        self::assertResponseStatusCodeSame(404);
        self::assertJsonStringEqualsJsonString('{"error":"board_disabled"}', (string) $client->getResponse()->getContent());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidBodies(): iterable
    {
        yield 'no rules key' => [[], 'rules'];
        yield 'rules not a list' => [['rules' => 'plan'], 'rules'];
        yield 'blank name' => [['rules' => [['name' => ''] + self::LIVE_RULE]], 'rules[0].name'];
        yield 'name of spaces' => [['rules' => [['name' => '   '] + self::LIVE_RULE]], 'rules[0].name'];
        yield 'column slug past the cap' => [['rules' => [['columns' => [str_repeat('a', 2001)]] + self::LIVE_RULE]], 'rules[0].columns[0]'];
        yield 'unknown state' => [['rules' => [['state' => 'sleeping'] + self::LIVE_RULE]], 'rules[0].state'];
        yield 'event type with spaces' => [['rules' => [['on' => 'card moved'] + self::LIVE_RULE]], 'rules[0].on'];
        yield 'column that is not a slug' => [['rules' => [['columns' => ['In Progress']] + self::LIVE_RULE]], 'rules[0].columns[0]'];
        yield 'dead without a reason' => [['rules' => [['reason' => null] + self::DEAD_RULE]], 'rules[0].reason'];
        yield 'live with a reason' => [['rules' => [['reason' => 'column_renamed'] + self::LIVE_RULE]], 'rules[0].reason'];
        yield 'reason that is prose' => [['rules' => [['reason' => 'The column was renamed'] + self::DEAD_RULE]], 'rules[0].reason'];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidBodies')]
    public function test_an_invalid_body_is_refused_with_the_field_that_failed(array $body, string $field): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'rules-api-invalid@example.com');
        $project = $this->project($em, $owner, 'Invalid Rules App');
        $raw = $this->agentToken($client, $owner);
        $this->enableBoard();

        $this->put($client, $this->path((string) $project->id, (string) Uuid::v4()), $raw, $body);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['violations'] ?? null);
        self::assertContains($field, array_column($data['violations'], 'propertyPath'));
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM board_bridge_rule_reports WHERE project_id = :project', ['project' => (string) $project->id]));
    }

    public function test_a_bridge_id_that_is_not_a_uuid_matches_no_route(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'rules-api-bridge-id@example.com');
        $project = $this->project($em, $owner, 'Bridge Id App');
        $raw = $this->agentToken($client, $owner);
        $this->enableBoard();

        $this->put($client, $this->path((string) $project->id, 'my-laptop'), $raw, ['rules' => [self::LIVE_RULE]]);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_widget_token_is_refused_by_the_firewall(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'rules-api-widget@example.com');
        $project = $this->project($em, $owner, 'Widget Rules App');
        $raw = AgentCredential::tokenFor(static::getContainer(), $client, $owner, 'site-review', $project);
        $this->enableBoard();

        $this->put($client, $this->path((string) $project->id, (string) Uuid::v4()), $raw, ['rules' => [self::LIVE_RULE]]);

        self::assertResponseStatusCodeSame(403);
        self::assertJsonStringEqualsJsonString('{"error":"insufficient_scope"}', (string) $client->getResponse()->getContent());
    }

    public function test_an_mcp_token_is_refused_by_the_firewall(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'rules-api-mcp@example.com');
        $project = $this->project($em, $owner, 'Mcp Rules App');
        $raw = AgentCredential::tokenFor(static::getContainer(), $client, $owner, 'mcp', $project);
        $this->enableBoard();

        $this->put($client, $this->path((string) $project->id, (string) Uuid::v4()), $raw, ['rules' => [self::LIVE_RULE]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function test_a_request_without_a_token_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'rules-api-anonymous@example.com'), 'Anonymous Rules App');
        $this->enableBoard();

        $client->request(Request::METHOD_PUT, $this->path((string) $project->id, (string) Uuid::v4()), server: ['CONTENT_TYPE' => 'application/json'], content: '{"rules":[]}');

        self::assertResponseStatusCodeSame(401);
    }

    /** A 429 on the second address proves the firewall resolved the token before the listener ran. */
    public function test_the_limit_counts_per_token(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('limiter.agent_bridge_rule_reports', new RateLimiterFactory(
            ['id' => 'agent_bridge_rule_reports', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
        $em = $this->em();
        $owner = $this->user($em, 'rules-api-limit@example.com');
        $project = $this->project($em, $owner, 'Limited Rules App');
        $other = $this->user($em, 'rules-api-limit-other@example.com');
        $otherProject = $this->project($em, $other, 'Other Limited Rules App');
        $first = $this->agentToken($client, $owner);
        // Two access tokens of one grant share a bucket, so the second budget
        // needs a second account.
        $second = $this->agentToken($client, $other);
        $this->enableBoard();
        $path = $this->path((string) $project->id, (string) Uuid::v4());

        $this->put($client, $path, $first, ['rules' => []], '203.0.113.7');
        self::assertResponseStatusCodeSame(204);

        $this->put($client, $path, $first, ['rules' => []], '198.51.100.4');
        self::assertResponseStatusCodeSame(429);

        $this->put($client, $this->path((string) $otherProject->id, (string) Uuid::v4()), $second, ['rules' => []], '203.0.113.7');
        self::assertResponseStatusCodeSame(204);
    }

    private function path(string $handle, string $bridge): string
    {
        return '/api/projects/'.$handle.'/bridges/'.$bridge.'/rules';
    }

    /** @return list<mixed> each stored rules list for that bridge, decoded */
    private function storedRules(EntityManagerInterface $em, Project $project, string $bridge): array
    {
        $rows = $em->getConnection()->fetchFirstColumn(
            'SELECT rules FROM board_bridge_rule_reports WHERE project_id = :project AND bridge_id = :bridge',
            ['project' => (string) $project->id, 'bridge' => $bridge],
        );

        return array_map(static fn (mixed $json): mixed => json_decode((string) $json, true, flags: \JSON_THROW_ON_ERROR), $rows);
    }

    private function receivedAt(EntityManagerInterface $em, Project $project, string $bridge): string
    {
        return (string) $em->getConnection()->fetchOne(
            'SELECT received_at FROM board_bridge_rule_reports WHERE project_id = :project AND bridge_id = :bridge',
            ['project' => (string) $project->id, 'bridge' => $bridge],
        );
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function agentToken(KernelBrowser $browser, User $owner): string
    {
        return AgentCredential::agentToken(static::getContainer(), $browser, $owner);
    }

    /** @param array<string, mixed> $body */
    private function put(KernelBrowser $client, string $path, string $raw, array $body, string $clientIp = '127.0.0.1'): void
    {
        $client->request(Request::METHOD_PUT, $path, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => $clientIp,
        ], content: json_encode((object) $body, \JSON_THROW_ON_ERROR));
    }
}
