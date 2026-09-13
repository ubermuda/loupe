<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Service\WorkerRunSearchIndexer;
use App\Outbox\AgentPush;
use App\Tests\Module\Bridge\BridgeScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

final class WorkerRunsApiTest extends WebTestCase
{
    use BridgeScenario;

    public function test_it_records_a_run_with_the_card_held_as_scalars(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'runs-api-create@example.com');
        $project = $this->project($em, $owner, 'Runs App');
        $raw = $this->agentToken($em, $owner);
        $bridgeId = (string) Uuid::v7();
        $cardId = (string) Uuid::v7();

        $this->post($client, '/api/projects/'.$project->id.'/worker-runs', $raw, [
            'bridgeId' => $bridgeId,
            'cardId' => $cardId,
            'cardNumber' => 42,
            'ruleName' => 'plan',
            'startedAt' => '2026-09-13T10:00:00+00:00',
            'endedAt' => '2026-09-13T10:00:21+00:00',
            'exitCode' => 0,
            'output' => "reading card 42\nwrote a plan",
        ]);

        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertTrue(Uuid::isValid((string) $body['id']));

        $run = $this->em()->find(WorkerRun::class, Uuid::fromString((string) $body['id']));
        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertSame((string) $project->id, (string) $run->project->id);
        self::assertSame($bridgeId, (string) $run->bridgeId);
        self::assertSame($cardId, (string) $run->cardId);
        self::assertSame(42, $run->cardNumber);
        self::assertSame('plan', $run->ruleName);
        self::assertSame(0, $run->exitCode);
        self::assertNull($run->failureReason);
        self::assertSame("reading card 42\nwrote a plan", $run->output);
        self::assertSame('2026-09-13T10:00:00+00:00', $run->startedAt->format(\DateTimeInterface::ATOM));
        self::assertSame('2026-09-13T10:00:21+00:00', $run->endedAt->format(\DateTimeInterface::ATOM));
    }

    /**
     * The bridge retries a report whose response it never saw, so the same run
     * arrives twice and must not become two rows.
     */
    public function test_a_repeated_report_answers_with_the_row_it_already_wrote(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'runs-api-retry@example.com');
        $project = $this->project($em, $owner, 'Retry Runs');
        $raw = $this->agentToken($em, $owner);
        $payload = $this->payload();
        $path = '/api/projects/'.$project->id.'/worker-runs';

        $this->post($client, $path, $raw, $payload);
        self::assertResponseStatusCodeSame(201);
        $first = $this->idOf($client);

        $this->post($client, $path, $raw, array_merge($payload, ['output' => 'a later retry says something else']));

        self::assertResponseStatusCodeSame(200);
        self::assertSame($first, $this->idOf($client));
        self::assertSame(1, $this->countRuns());
        self::assertSame('ok', $this->onlyRun()->output);
    }

    /**
     * The column holds whole seconds, so the stored value and the value a retry
     * sends must floor the same way. Otherwise a retry misses the read and the
     * insert trips the unique index.
     */
    public function test_a_retry_carrying_a_fraction_of_a_second_still_finds_its_row(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'runs-api-fraction@example.com');
        $project = $this->project($em, $owner, 'Fraction Runs');
        $raw = $this->agentToken($em, $owner);
        $payload = $this->payload([
            'startedAt' => '2026-09-13T10:00:00.750+00:00',
            'endedAt' => '2026-09-13T10:00:21.250+00:00',
        ]);
        $path = '/api/projects/'.$project->id.'/worker-runs';

        $this->post($client, $path, $raw, $payload);
        self::assertResponseStatusCodeSame(201);
        $first = $this->idOf($client);

        $this->post($client, $path, $raw, $payload);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($first, $this->idOf($client));
        self::assertSame(1, $this->countRuns());
    }

    /** A second run of the same card is a new run, and the start time is what tells them apart. */
    public function test_a_later_run_of_the_same_card_is_a_second_row(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'runs-api-second@example.com');
        $project = $this->project($em, $owner, 'Second Runs');
        $raw = $this->agentToken($em, $owner);
        $payload = $this->payload();
        $path = '/api/projects/'.$project->id.'/worker-runs';

        $this->post($client, $path, $raw, $payload);
        self::assertResponseStatusCodeSame(201);

        $this->post($client, $path, $raw, array_merge($payload, [
            'startedAt' => '2026-09-13T11:00:00+00:00',
            'endedAt' => '2026-09-13T11:00:21+00:00',
        ]));

        self::assertResponseStatusCodeSame(201);
        self::assertSame(2, $this->countRuns());
    }

    /** The server stamps arrival, so a bridge with a wrong clock cannot backdate a row out of the retention window. */
    public function test_the_server_stamps_the_arrival_and_the_bridge_clock_stays_as_reported(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'runs-api-clock@example.com');
        $project = $this->project($em, $owner, 'Clock App');
        $raw = $this->agentToken($em, $owner);
        $before = new \DateTimeImmutable('-1 minute');

        $this->post($client, '/api/projects/'.$project->id.'/worker-runs', $raw, $this->payload([
            'startedAt' => '1999-01-01T00:00:00+00:00',
            'endedAt' => '1999-01-01T00:01:00+00:00',
        ]));

        self::assertResponseStatusCodeSame(201);
        $run = $this->onlyRun();
        self::assertSame('1999-01-01', $run->startedAt->format('Y-m-d'));
        self::assertGreaterThan($before, $run->receivedAt);
    }

    /**
     * The vector is written by Postgres and nothing else reads it yet, so the
     * assertion goes through a query the page will later run.
     */
    public function test_the_search_vector_covers_the_card_number_the_rule_name_and_the_output(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'runs-api-search@example.com');
        $project = $this->project($em, $owner, 'Search App');
        $raw = $this->agentToken($em, $owner);

        $this->post($client, '/api/projects/'.$project->id.'/worker-runs', $raw, $this->payload([
            'cardNumber' => 137,
            'ruleName' => 'brainstorm',
            'output' => 'migration applied cleanly',
        ]));

        self::assertResponseStatusCodeSame(201);
        $run = $this->onlyRun();
        // Postgres wrote the column after Doctrine loaded the row, so the
        // identity map still holds the object the insert built.
        $this->em()->clear();
        $reloaded = $this->em()->find(WorkerRun::class, $run->id);
        self::assertInstanceOf(WorkerRun::class, $reloaded);
        self::assertNotNull($reloaded->searchVector);

        foreach (['137', 'brainstorm', 'migration', 'cleanly'] as $term) {
            self::assertTrue($this->vectorMatches((string) $run->id, $term), $term);
        }
        self::assertFalse($this->vectorMatches((string) $run->id, 'nothinglikethis'));
    }

    public function test_a_spawn_failure_is_stored_with_no_exit_code(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'runs-api-spawn@example.com');
        $project = $this->project($em, $owner, 'Spawn App');
        $raw = $this->agentToken($em, $owner);

        $this->post($client, '/api/projects/'.$project->id.'/worker-runs', $raw, $this->payload([
            'exitCode' => null,
            'failureReason' => 'exec: "claude": executable file not found in $PATH',
        ]));

        self::assertResponseStatusCodeSame(201);
        $run = $this->onlyRun();
        self::assertNull($run->exitCode);
        self::assertSame('exec: "claude": executable file not found in $PATH', $run->failureReason);
    }

    /** The two faults must stay distinguishable, so neither half of the pair may stand alone. */
    public function test_a_missing_exit_code_without_a_reason_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'runs-api-noreason@example.com');
        $project = $this->project($em, $owner, 'No Reason App');
        $raw = $this->agentToken($em, $owner);

        foreach ([null, '', '   '] as $reason) {
            $this->post($client, '/api/projects/'.$project->id.'/worker-runs', $raw, $this->payload([
                'exitCode' => null,
                'failureReason' => $reason,
            ]));

            self::assertResponseStatusCodeSame(422, var_export($reason, true));
        }

        self::assertSame(0, $this->countRuns());
    }

    public function test_an_exit_code_with_a_reason_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'runs-api-bothfaults@example.com');
        $project = $this->project($em, $owner, 'Both App');
        $raw = $this->agentToken($em, $owner);

        $this->post($client, '/api/projects/'.$project->id.'/worker-runs', $raw, $this->payload([
            'exitCode' => 1,
            'failureReason' => 'also a reason',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->countRuns());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidPayloads(): iterable
    {
        yield 'a bridge id that is not a uuid' => [['bridgeId' => 'not-a-uuid']];
        yield 'a card id that is not a uuid' => [['cardId' => 'not-a-uuid']];
        yield 'a card number of zero' => [['cardNumber' => 0]];
        yield 'a blank rule name' => [['ruleName' => '   ']];
        yield 'a rule name past the column' => [['ruleName' => str_repeat('r', 101)]];
        yield 'a malformed start date' => [['startedAt' => 'yesterday afternoon']];
        yield 'a missing end date' => [['endedAt' => null]];
        yield 'an end before the start' => [['endedAt' => '2026-09-13T09:59:59+00:00']];
        yield 'an exit code out of range' => [['exitCode' => 100000]];
        yield 'output past the cap' => [['output' => str_repeat('x', WorkerRun::MAX_OUTPUT_LENGTH + 1)]];
        yield 'a missing output' => [['output' => null]];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalidPayloads')]
    public function test_an_invalid_payload_is_refused(array $overrides): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'runs-api-invalid'.md5(serialize($overrides)).'@example.com');
        $project = $this->project($em, $owner, 'Invalid App '.substr(md5(serialize($overrides)), 0, 6));
        $raw = $this->agentToken($em, $owner);

        $this->post($client, '/api/projects/'.$project->id.'/worker-runs', $raw, $this->payload($overrides));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->countRuns());
    }

    /** The output cap is the server's own, so it holds whatever the bridge sends. */
    public function test_output_at_the_cap_is_accepted(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'runs-api-cap@example.com');
        $project = $this->project($em, $owner, 'Cap App');
        $raw = $this->agentToken($em, $owner);

        $this->post($client, '/api/projects/'.$project->id.'/worker-runs', $raw, $this->payload([
            'output' => str_repeat('x', WorkerRun::MAX_OUTPUT_LENGTH),
        ]));

        self::assertResponseStatusCodeSame(201);
        self::assertSame(WorkerRun::MAX_OUTPUT_LENGTH, mb_strlen($this->onlyRun()->output));
    }

    public function test_the_handle_can_be_the_project_slug(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'runs-api-slug@example.com');
        $this->project($em, $owner, 'Slugged Runs');
        $raw = $this->agentToken($em, $owner);

        $this->post($client, '/api/projects/slugged-runs/worker-runs', $raw, $this->payload());

        self::assertResponseStatusCodeSame(201);
    }

    public function test_another_users_project_is_indistinguishable_from_an_unknown_one(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $caller = $this->user($em, 'runs-api-caller@example.com');
        $raw = $this->agentToken($em, $caller);
        $other = $this->project($em, $this->user($em, 'runs-api-other@example.com'), 'Private Runs');

        foreach ([(string) $other->id, 'private-runs', (string) Uuid::v7()] as $handle) {
            $this->post($client, '/api/projects/'.rawurlencode($handle).'/worker-runs', $raw, $this->payload());

            self::assertResponseStatusCodeSame(404, $handle);
            self::assertJsonStringEqualsJsonString(
                '{"error":"project_not_found"}',
                (string) $client->getResponse()->getContent(),
                $handle,
            );
        }

        self::assertSame(0, $this->countRuns());
    }

    public function test_it_is_absent_while_push_is_switched_off(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'runs-api-flag@example.com');
        $project = $this->project($em, $owner, 'Flagged Runs');
        $raw = $this->agentToken($em, $owner);
        // The flag ships on (a migration seeds it), so this case turns it off: a
        // valid report refused only because the instance does not do push.
        $em->getConnection()->executeStatement(
            "UPDATE feature_flag SET value = 'false' WHERE name = ?",
            [AgentPush::FLAG],
        );

        $this->post($client, '/api/projects/'.$project->id.'/worker-runs', $raw, $this->payload());

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->countRuns());
    }

    public function test_a_widget_token_is_refused_by_the_firewall(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'runs-api-widget@example.com');
        $project = $this->project($em, $owner, 'Widget Runs');
        [$token, $raw] = ApiToken::issue($owner, 'widget', ApiTokenScope::SiteReview);
        $em->persist($token);
        $project->widgetToken = $token;
        $em->flush();

        $this->post($client, '/api/projects/'.$project->id.'/worker-runs', $raw, $this->payload());

        self::assertResponseStatusCodeSame(403);
        self::assertJsonStringEqualsJsonString(
            '{"error":"insufficient_scope"}',
            (string) $client->getResponse()->getContent(),
        );
        self::assertSame(0, $this->countRuns());
    }

    public function test_an_mcp_token_is_refused_by_the_firewall(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'runs-api-mcp@example.com');
        $project = $this->project($em, $owner, 'Mcp Runs');
        [$token, $raw] = ApiToken::issue($owner, 'mcp', ApiTokenScope::Mcp);
        $em->persist($token);
        $project->mcpToken = $token;
        $em->flush();

        $this->post($client, '/api/projects/'.$project->id.'/worker-runs', $raw, $this->payload());

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->countRuns());
    }

    public function test_a_request_without_a_token_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'runs-api-anonymous@example.com'), 'Anonymous Runs');

        $client->request(
            Request::METHOD_POST,
            '/api/projects/'.$project->id.'/worker-runs',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->payload(), \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(401);
        self::assertSame(0, $this->countRuns());
    }

    /**
     * With the token unresolved, the listener would key on the address, and the
     * second report below would pass. A 429 proves the firewall ran first.
     */
    public function test_the_limit_counts_per_token_because_the_firewall_runs_first(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('limiter.agent_worker_runs', new RateLimiterFactory(
            ['id' => 'agent_worker_runs', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
        $em = $this->em();
        $owner = $this->user($em, 'runs-api-limit@example.com');
        $project = $this->project($em, $owner, 'Limited Runs');
        $first = $this->agentToken($em, $owner);
        $second = $this->agentToken($em, $owner);
        $path = '/api/projects/'.$project->id.'/worker-runs';

        $this->post($client, $path, $first, $this->payload(), '203.0.113.7');
        self::assertResponseStatusCodeSame(201);

        $this->post($client, $path, $first, $this->payload(), '198.51.100.4');
        self::assertResponseStatusCodeSame(429);

        $this->post($client, $path, $second, $this->payload(), '203.0.113.7');
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'bridgeId' => (string) Uuid::v7(),
            'cardId' => (string) Uuid::v7(),
            'cardNumber' => 1,
            'ruleName' => 'plan',
            'startedAt' => '2026-09-13T10:00:00+00:00',
            'endedAt' => '2026-09-13T10:00:21+00:00',
            'exitCode' => 0,
            'output' => 'ok',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(KernelBrowser $client, string $path, string $raw, array $payload, string $clientIp = '127.0.0.1'): void
    {
        $client->request(
            Request::METHOD_POST,
            $path,
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'REMOTE_ADDR' => $clientIp,
            ],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    private function idOf(KernelBrowser $client): string
    {
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return (string) $body['id'];
    }

    /** @return list<WorkerRun> */
    private function allRuns(): array
    {
        /** @var list<WorkerRun> $runs */
        $runs = $this->em()->createQuery('SELECT r FROM '.WorkerRun::class.' r')->getResult();

        return $runs;
    }

    private function onlyRun(): WorkerRun
    {
        $runs = $this->allRuns();
        self::assertCount(1, $runs);

        return $runs[0];
    }

    private function countRuns(): int
    {
        return \count($this->allRuns());
    }

    private function vectorMatches(string $id, string $term): bool
    {
        return (bool) $this->em()->getConnection()->fetchOne(
            \sprintf(
                'SELECT search_vector @@ websearch_to_tsquery(\'%s\', :term) FROM bridge_worker_runs WHERE id = :id',
                WorkerRunSearchIndexer::LANGUAGE->value,
            ),
            ['term' => $term, 'id' => $id],
        );
    }
}
