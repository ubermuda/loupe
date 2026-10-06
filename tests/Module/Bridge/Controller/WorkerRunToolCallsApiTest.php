<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Project\Entity\Project;
use App\Outbox\AgentPush;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\AgentCredential;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

final class WorkerRunToolCallsApiTest extends WebTestCase
{
    use BridgeScenario;

    public function test_a_batch_stores_its_calls_and_a_repeat_changes_nothing(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'tool-calls-store@example.com');
        $project = $this->project($em, $owner, 'Tool Calls Store');
        $run = $this->keyedRun($project);
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $project, $run, $raw, ['calls' => [
            self::call(1, 'Bash', durationMs: 1200, signatures: ['git status']),
            self::call(2, 'Read', durationMs: null, isError: null, backgroundId: 'bg-1', waitsOn: 'bg-0', fullText: 'cat README.md'),
        ], 'timing' => null]);

        self::assertResponseStatusCodeSame(200);
        self::assertJsonStringEqualsJsonString('{"stored":2}', (string) $client->getResponse()->getContent());
        $stored = $this->rows($run);
        self::assertSame([
            ['seq' => 1, 'tool' => 'Bash', 'started_at' => '2026-01-01 10:00:01', 'duration_ms' => 1200, 'is_error' => false, 'in_subagent' => false, 'background_id' => null, 'waits_on' => null, 'signatures' => '["git status"]', 'full_text' => null],
            ['seq' => 2, 'tool' => 'Read', 'started_at' => '2026-01-01 10:00:02', 'duration_ms' => null, 'is_error' => null, 'in_subagent' => false, 'background_id' => 'bg-1', 'waits_on' => 'bg-0', 'signatures' => '["Read"]', 'full_text' => 'cat README.md'],
        ], $stored);

        $this->put($client, $project, $run, $raw, ['calls' => [
            self::call(1, 'Edit', durationMs: 9),
            self::call(2, 'Edit', durationMs: 9),
        ], 'timing' => null]);

        self::assertResponseStatusCodeSame(200);
        self::assertJsonStringEqualsJsonString('{"stored":0}', (string) $client->getResponse()->getContent());
        self::assertSame($stored, $this->rows($run));
    }

    public function test_a_seq_the_batch_repeats_is_stored_once(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'tool-calls-twice@example.com');
        $project = $this->project($em, $owner, 'Tool Calls Twice');
        $run = $this->keyedRun($project);

        $this->put($client, $project, $run, $this->agentToken($client, $owner), ['calls' => [
            self::call(1, 'Bash'),
            self::call(1, 'Read'),
        ], 'timing' => null]);

        self::assertResponseStatusCodeSame(200);
        self::assertJsonStringEqualsJsonString('{"stored":1}', (string) $client->getResponse()->getContent());
        self::assertSame(['Bash'], array_column($this->rows($run), 'tool'));
    }

    /** The seeded run lasts 300 000 ms. */
    public function test_the_fact_row_shows_the_timing_and_the_call_metrics(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'tool-calls-facts@example.com');
        $project = $this->project($em, $owner, 'Tool Calls Facts');
        $run = $this->keyedRun($project);

        $this->put($client, $project, $run, $this->agentToken($client, $owner), ['calls' => [
            self::call(1, 'Bash', durationMs: 1000, isError: false),
            self::call(2, 'Read', durationMs: 3000, isError: true),
            self::call(3, 'Agent', durationMs: 50000),
            self::call(4, 'Task', durationMs: 7000, inSubagent: true),
            self::call(5, 'Task', durationMs: 2000),
            self::call(6, 'Agent', durationMs: null, isError: null),
        ], 'timing' => ['toolTimeMs' => 60000, 'idleGapMs' => 40000]]);

        self::assertResponseStatusCodeSame(200);
        $reloaded = $this->runOf($run);
        self::assertSame(60000, $reloaded->toolTimeMs);
        self::assertSame(40000, $reloaded->idleGapMs);
        self::assertSame([
            'tool_time_ms' => 60000,
            'model_time_ms' => 200000,
            'tool_calls' => 6,
            'failed_calls' => 1,
            'longest_call_ms' => 50000,
            'idle_gap_ms' => 40000,
            'subagent_ms' => 52000,
        ], $this->facts($run));
    }

    public function test_a_run_with_no_tool_calls_has_unknown_call_metrics(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'tool-calls-none@example.com');
        $project = $this->project($em, $owner, 'Tool Calls None');
        $run = $this->keyedRun($project);

        $this->put($client, $project, $run, $this->agentToken($client, $owner), ['calls' => [], 'timing' => ['toolTimeMs' => 0, 'idleGapMs' => 0]]);

        self::assertResponseStatusCodeSame(200);
        self::assertJsonStringEqualsJsonString('{"stored":0}', (string) $client->getResponse()->getContent());
        self::assertSame([
            'tool_time_ms' => 0,
            'model_time_ms' => 300000,
            'tool_calls' => null,
            'failed_calls' => null,
            'longest_call_ms' => null,
            'idle_gap_ms' => 0,
            'subagent_ms' => null,
        ], $this->facts($run));
    }

    public function test_a_run_with_calls_and_no_subagent_call_spends_no_subagent_time(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'tool-calls-no-agent@example.com');
        $project = $this->project($em, $owner, 'Tool Calls No Agent');
        $run = $this->keyedRun($project);

        $this->put($client, $project, $run, $this->agentToken($client, $owner), ['calls' => [self::call(1, 'Bash', durationMs: null)], 'timing' => null]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            'tool_time_ms' => null,
            'model_time_ms' => null,
            'tool_calls' => 1,
            'failed_calls' => 0,
            'longest_call_ms' => null,
            'idle_gap_ms' => null,
            'subagent_ms' => 0,
        ], $this->facts($run));
    }

    public function test_the_model_time_never_falls_below_zero(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'tool-calls-floor@example.com');
        $project = $this->project($em, $owner, 'Tool Calls Floor');
        $run = $this->keyedRun($project);

        $this->put($client, $project, $run, $this->agentToken($client, $owner), ['calls' => [], 'timing' => ['toolTimeMs' => 250000, 'idleGapMs' => 100000]]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $this->facts($run)['model_time_ms']);
    }

    /** GREATEST(0, NULL) is 0 in Postgres, so an open run would read as a run with no model time. */
    public function test_the_model_time_is_unknown_while_the_run_has_no_end(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'tool-calls-open@example.com');
        $project = $this->project($em, $owner, 'Tool Calls Open');
        $run = $this->keyedRun($project);
        $run->endedAt = null;
        $em->flush();

        $this->put($client, $project, $run, $this->agentToken($client, $owner), ['calls' => [], 'timing' => ['toolTimeMs' => 1000, 'idleGapMs' => 0]]);

        self::assertResponseStatusCodeSame(200);
        $facts = $this->facts($run);
        self::assertSame(1000, $facts['tool_time_ms']);
        self::assertNull($facts['model_time_ms']);
    }

    public function test_a_batch_with_timing_replaces_the_timing_and_one_without_keeps_it(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'tool-calls-timing@example.com');
        $project = $this->project($em, $owner, 'Tool Calls Timing');
        $run = $this->keyedRun($project);
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $project, $run, $raw, ['calls' => [], 'timing' => ['toolTimeMs' => 5000, 'idleGapMs' => 7000]]);
        $this->put($client, $project, $run, $raw, ['calls' => [], 'timing' => null]);
        self::assertSame([5000, 7000], [$this->runOf($run)->toolTimeMs, $this->runOf($run)->idleGapMs]);

        $this->put($client, $project, $run, $raw, ['calls' => [], 'timing' => ['toolTimeMs' => 6000, 'idleGapMs' => null]]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([6000, null], [$this->runOf($run)->toolTimeMs, $this->runOf($run)->idleGapMs]);
    }

    public function test_a_run_of_another_project_answers_run_not_found(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'tool-calls-foreign@example.com');
        $project = $this->project($em, $owner, 'Tool Calls Here');
        $elsewhere = $this->keyedRun($this->project($em, $owner, 'Tool Calls Elsewhere'));
        $stranger = $this->keyedRun($this->project($em, $this->user($em, 'tool-calls-stranger@example.com'), 'Tool Calls Stranger'));
        $raw = $this->agentToken($client, $owner);

        foreach ([$elsewhere, $stranger] as $run) {
            $this->put($client, $project, $run, $raw, ['calls' => [self::call(1, 'Bash')], 'timing' => null]);

            self::assertResponseStatusCodeSame(404);
            self::assertJsonStringEqualsJsonString('{"error":"run_not_found"}', (string) $client->getResponse()->getContent());
            self::assertSame([], $this->rows($run));
        }
    }

    /** The path names the key the bridge gave the run, never the id the server gave it. */
    public function test_the_server_id_of_a_run_answers_run_not_found(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'tool-calls-server-id@example.com');
        $project = $this->project($em, $owner, 'Tool Calls Server Id');
        $run = $this->keyedRun($project);

        $this->request($client, '/api/projects/'.$project->id.'/worker-runs/'.$run->id.'/tool-calls', $this->agentToken($client, $owner), ['calls' => [], 'timing' => null]);

        self::assertResponseStatusCodeSame(404);
        self::assertJsonStringEqualsJsonString('{"error":"run_not_found"}', (string) $client->getResponse()->getContent());
    }

    public function test_another_users_project_answers_project_not_found(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $caller = $this->user($em, 'tool-calls-caller@example.com');
        $other = $this->project($em, $this->user($em, 'tool-calls-other@example.com'), 'Tool Calls Private');
        $run = $this->keyedRun($other);

        $this->put($client, $other, $run, $this->agentToken($client, $caller), ['calls' => [self::call(1, 'Bash')], 'timing' => null]);

        self::assertResponseStatusCodeSame(404);
        self::assertJsonStringEqualsJsonString('{"error":"project_not_found"}', (string) $client->getResponse()->getContent());
        self::assertSame([], $this->rows($run));
    }

    public function test_a_run_id_that_is_not_a_uuid_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'tool-calls-bad-id@example.com');
        $project = $this->project($em, $owner, 'Tool Calls Bad Id');

        $this->request($client, '/api/projects/'.$project->id.'/worker-runs/not-a-uuid/tool-calls', $this->agentToken($client, $owner), ['calls' => [], 'timing' => null]);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonStringEqualsJsonString('{"error":"invalid_run_id"}', (string) $client->getResponse()->getContent());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidBodies(): iterable
    {
        yield 'no calls' => [['timing' => null]];
        yield 'too many calls' => [['calls' => array_map(static fn (int $seq): array => self::call($seq, 'Bash'), range(1, 501)), 'timing' => null]];
        yield 'a seq of zero' => [['calls' => [self::call(0, 'Bash')], 'timing' => null]];
        yield 'a blank tool' => [['calls' => [self::call(1, '')], 'timing' => null]];
        yield 'a long tool' => [['calls' => [self::call(1, str_repeat('t', 65))], 'timing' => null]];
        yield 'no start' => [['calls' => [['startedAt' => null] + self::call(1, 'Bash')], 'timing' => null]];
        yield 'a negative duration' => [['calls' => [self::call(1, 'Bash', durationMs: -1)], 'timing' => null]];
        yield 'no subagent flag' => [['calls' => [['inSubagent' => null] + self::call(1, 'Bash')], 'timing' => null]];
        yield 'a long background id' => [['calls' => [self::call(1, 'Bash', backgroundId: str_repeat('b', 65))], 'timing' => null]];
        yield 'too many signatures' => [['calls' => [self::call(1, 'Bash', signatures: array_fill(0, 21, 'git'))], 'timing' => null]];
        yield 'a long signature' => [['calls' => [self::call(1, 'Bash', signatures: [str_repeat('s', 121)])], 'timing' => null]];
        yield 'signatures as an object' => [['calls' => [self::call(1, 'Bash', signatures: ['a' => 'git'])], 'timing' => null]];
        yield 'a long full text' => [['calls' => [self::call(1, 'Bash', fullText: str_repeat('x', 20001))], 'timing' => null]];
        yield 'a negative tool time' => [['calls' => [], 'timing' => ['toolTimeMs' => -1, 'idleGapMs' => 0]]];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidBodies')]
    public function test_an_invalid_body_is_refused(array $body): void
    {
        $client = static::createClient();
        $em = $this->em();
        $suffix = md5(serialize($body));
        $owner = $this->user($em, 'tool-calls-invalid-'.$suffix.'@example.com');
        $project = $this->project($em, $owner, 'Tool Calls Invalid '.substr($suffix, 0, 6));
        $run = $this->keyedRun($project);

        $this->put($client, $project, $run, $this->agentToken($client, $owner), $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->rows($run));
    }

    /** The bridge reads a 404 with no error code as a server with no tool call endpoint. */
    public function test_it_is_absent_with_no_error_code_while_push_is_switched_off(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'tool-calls-flag@example.com');
        $project = $this->project($em, $owner, 'Tool Calls Flag');
        $run = $this->keyedRun($project);
        $raw = $this->agentToken($client, $owner);
        $em->getConnection()->executeStatement("UPDATE feature_flag SET value = 'false' WHERE name = ?", [AgentPush::FLAG]);

        $this->put($client, $project, $run, $raw, ['calls' => [self::call(1, 'Bash')], 'timing' => null]);

        self::assertResponseStatusCodeSame(404);
        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('project_not_found', $body);
        self::assertStringNotContainsString('run_not_found', $body);
    }

    public function test_a_widget_token_is_refused_by_the_firewall(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'tool-calls-widget@example.com');
        $project = $this->project($em, $owner, 'Tool Calls Widget');
        $run = $this->keyedRun($project);
        $raw = AgentCredential::tokenFor(static::getContainer(), $owner, 'site-review', $project);

        $this->put($client, $project, $run, $raw, ['calls' => [self::call(1, 'Bash')], 'timing' => null]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->rows($run));
    }

    public function test_tool_call_reports_share_the_run_report_limit(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('limiter.agent_worker_runs', new RateLimiterFactory(
            ['id' => 'agent_worker_runs', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
        $em = $this->em();
        $owner = $this->user($em, 'tool-calls-limit@example.com');
        $project = $this->project($em, $owner, 'Tool Calls Limit');
        $run = $this->keyedRun($project);
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $project, $run, $raw, ['calls' => [], 'timing' => null]);
        self::assertResponseStatusCodeSame(200);

        $this->put($client, $project, $run, $raw, ['calls' => [], 'timing' => null]);
        self::assertResponseStatusCodeSame(429);
    }

    /**
     * @param array<array-key, string>|null $signatures
     *
     * @return array<string, mixed>
     */
    private static function call(
        int $seq,
        string $tool,
        ?int $durationMs = 100,
        ?bool $isError = false,
        bool $inSubagent = false,
        ?string $backgroundId = null,
        ?string $waitsOn = null,
        ?array $signatures = null,
        ?string $fullText = null,
    ): array {
        return [
            'seq' => $seq,
            'tool' => $tool,
            'startedAt' => \sprintf('2026-01-01T11:00:%02d.250+01:00', $seq % 60),
            'durationMs' => $durationMs,
            'isError' => $isError,
            'inSubagent' => $inSubagent,
            'backgroundId' => $backgroundId,
            'waitsOn' => $waitsOn,
            'signatures' => $signatures ?? [$tool],
            'fullText' => $fullText,
        ];
    }

    private function keyedRun(Project $project): WorkerRun
    {
        return $this->seedRun($this->em(), $project, runKey: Uuid::v4());
    }

    /** @param array<string, mixed> $body */
    private function put(KernelBrowser $client, Project $project, WorkerRun $run, string $raw, array $body): void
    {
        $this->request($client, '/api/projects/'.$project->id.'/worker-runs/'.$run->runKey.'/tool-calls', $raw, $body);
    }

    /** @param array<string, mixed> $body */
    private function request(KernelBrowser $client, string $path, string $raw, array $body): void
    {
        $client->request(
            Request::METHOD_PUT,
            $path,
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    /** @return list<array<string, mixed>> */
    private function rows(WorkerRun $run): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT seq, tool, started_at, duration_ms, is_error, in_subagent, background_id, waits_on, signatures::text AS signatures, full_text
            FROM bridge_worker_run_tool_calls WHERE run_id = ? ORDER BY seq',
            [(string) $run->id],
        );

        return $rows;
    }

    /** @return array<string, int|null> */
    private function facts(WorkerRun $run): array
    {
        $row = $this->em()->getConnection()->fetchAssociative(
            'SELECT tool_time_ms, model_time_ms, tool_calls, failed_calls, longest_call_ms, idle_gap_ms, subagent_ms
            FROM bridge_worker_run_facts WHERE run_id = ?',
            [(string) $run->id],
        );
        self::assertIsArray($row);

        return array_map(static fn (mixed $value): ?int => null === $value ? null : (int) $value, $row);
    }

    private function runOf(WorkerRun $run): WorkerRun
    {
        $this->em()->clear();

        return $this->em()->find(WorkerRun::class, $run->id) ?? throw new \LogicException('The run is gone.');
    }
}
