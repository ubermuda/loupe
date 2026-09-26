<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
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

final class WorkerRunSessionUsageApiTest extends WebTestCase
{
    use BridgeScenario;

    /**
     * The processes follow the start order of the runs. A run of another
     * project, an interactive run and a run that never started are not
     * processes of the session.
     */
    public function test_each_process_fills_the_run_it_matches_in_start_order(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'session-usage-order@example.com');
        $project = $this->project($em, $owner, 'Session Usage Order');
        $session = Uuid::v4();
        $later = $this->sessionRun($project, $session, '2026-09-23 10:05:00');
        $earlier = $this->sessionRun($project, $session, '2026-09-23 10:00:00');
        $this->sessionRun($project, $session, '2026-09-23 09:00:00', WorkerRunKind::Interactive);
        $this->sessionRun($project, $session, null);
        $this->sessionRun($this->project($em, $owner, 'Session Usage Elsewhere'), $session, '2026-09-23 09:00:00');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $project, (string) $session, $raw, ['processes' => [
            self::process('estimated', 'claude-first'),
            self::process('estimated', 'claude-second'),
        ]]);

        self::assertResponseStatusCodeSame(200);
        self::assertJsonStringEqualsJsonString('{"runs":2,"updated":2}', (string) $client->getResponse()->getContent());
        self::assertSame([
            [(string) $earlier->id, 'claude-first'],
            [(string) $later->id, 'claude-second'],
        ], $this->usageRows());
        self::assertSame(WorkerRunUsageSource::Estimated, $this->runOf($earlier)->usageSource);
    }

    public function test_reported_counts_replace_an_estimate_and_an_estimate_never_replaces_reported_counts(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'session-usage-merge@example.com');
        $project = $this->project($em, $owner, 'Session Usage Merge');
        $session = Uuid::v4();
        $estimated = $this->sessionRun($project, $session, '2026-09-23 10:00:00');
        $reported = $this->sessionRun($project, $session, '2026-09-23 10:05:00');
        $this->seedUsage($em, $estimated, 'claude-estimated');
        $estimated->usageSource = WorkerRunUsageSource::Estimated;
        $this->seedUsage($em, $reported, 'claude-reported');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $project, (string) $session, $raw, ['processes' => [
            self::process('reported', 'claude-now-reported'),
            self::process('estimated', 'claude-lower'),
        ]]);

        self::assertResponseStatusCodeSame(200);
        self::assertJsonStringEqualsJsonString('{"runs":2,"updated":1}', (string) $client->getResponse()->getContent());
        self::assertSame([
            [(string) $estimated->id, 'claude-now-reported'],
            [(string) $reported->id, 'claude-reported'],
        ], $this->usageRows());
        self::assertSame(WorkerRunUsageSource::Reported, $this->runOf($estimated)->usageSource);
    }

    public function test_a_process_with_no_models_records_a_run_that_spent_nothing(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'session-usage-zero@example.com');
        $project = $this->project($em, $owner, 'Session Usage Zero');
        $session = Uuid::v4();
        $run = $this->sessionRun($project, $session, '2026-09-23 10:00:00');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $project, (string) $session, $raw, ['processes' => [['source' => 'reported', 'models' => []]]]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(WorkerRunUsageSource::Reported, $this->runOf($run)->usageSource);
        self::assertSame([], $this->usageRows());
    }

    /** The bridge and the server disagree on the processes, so no run can be matched safely. */
    public function test_a_process_count_that_differs_from_the_runs_writes_nothing(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'session-usage-mismatch@example.com');
        $project = $this->project($em, $owner, 'Session Usage Mismatch');
        $session = Uuid::v4();
        $run = $this->sessionRun($project, $session, '2026-09-23 10:00:00');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $project, (string) $session, $raw, ['processes' => [
            self::process('reported', 'claude-one'),
            self::process('reported', 'claude-two'),
        ]]);

        self::assertResponseStatusCodeSame(409);
        self::assertJsonStringEqualsJsonString('{"error":"process_count_mismatch"}', (string) $client->getResponse()->getContent());
        self::assertSame([], $this->usageRows());
        self::assertNull($this->runOf($run)->usageSource);
    }

    /** The column holds whole seconds, so two starts in one second leave the process order unknown. */
    public function test_two_runs_that_start_in_the_same_second_write_nothing(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'session-usage-tie@example.com');
        $project = $this->project($em, $owner, 'Session Usage Tie');
        $session = Uuid::v4();
        $first = $this->sessionRun($project, $session, '2026-09-23 10:00:00');
        $this->sessionRun($project, $session, '2026-09-23 10:00:00');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $project, (string) $session, $raw, ['processes' => [
            self::process('reported', 'claude-one'),
            self::process('reported', 'claude-two'),
        ]]);

        self::assertResponseStatusCodeSame(409);
        self::assertJsonStringEqualsJsonString('{"error":"ambiguous_start_order"}', (string) $client->getResponse()->getContent());
        self::assertSame([], $this->usageRows());
        self::assertNull($this->runOf($first)->usageSource);
    }

    public function test_a_session_with_no_runs_is_a_count_mismatch(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'session-usage-none@example.com');
        $project = $this->project($em, $owner, 'Session Usage None');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $project, (string) Uuid::v4(), $raw, ['processes' => [self::process('reported', 'claude')]]);

        self::assertResponseStatusCodeSame(409);
        self::assertJsonStringEqualsJsonString('{"error":"process_count_mismatch"}', (string) $client->getResponse()->getContent());
    }

    public function test_another_users_project_answers_project_not_found(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $caller = $this->user($em, 'session-usage-caller@example.com');
        $other = $this->project($em, $this->user($em, 'session-usage-other@example.com'), 'Session Usage Private');
        $session = Uuid::v4();
        $this->sessionRun($other, $session, '2026-09-23 10:00:00');
        $raw = $this->agentToken($client, $caller);

        $this->put($client, $other, (string) $session, $raw, ['processes' => [self::process('reported', 'claude')]]);

        self::assertResponseStatusCodeSame(404);
        self::assertJsonStringEqualsJsonString('{"error":"project_not_found"}', (string) $client->getResponse()->getContent());
        self::assertSame([], $this->usageRows());
    }

    public function test_a_session_id_that_is_not_a_uuid_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'session-usage-bad-id@example.com');
        $project = $this->project($em, $owner, 'Session Usage Bad Id');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $project, 'not-a-uuid', $raw, ['processes' => [self::process('reported', 'claude')]]);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonStringEqualsJsonString('{"error":"invalid_session_id"}', (string) $client->getResponse()->getContent());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidBodies(): iterable
    {
        yield 'no processes' => [[]];
        yield 'an empty list of processes' => [['processes' => []]];
        yield 'a process from an unknown source' => [['processes' => [['source' => 'guessed', 'models' => []]]]];
        yield 'a process with a negative token count' => [['processes' => [['source' => 'reported', 'models' => ['claude' => [
            'inputTokens' => -1, 'outputTokens' => 0, 'cacheReadTokens' => 0, 'cacheWriteTokens' => 0, 'costUsd' => null,
        ]]]]]];
        yield 'too many processes' => [['processes' => array_fill(0, 101, ['source' => 'reported', 'models' => []])]];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidBodies')]
    public function test_an_invalid_body_is_refused(array $body): void
    {
        $client = static::createClient();
        $em = $this->em();
        $suffix = md5(serialize($body));
        $owner = $this->user($em, 'session-usage-invalid-'.$suffix.'@example.com');
        $project = $this->project($em, $owner, 'Session Usage Invalid '.substr($suffix, 0, 6));
        $session = Uuid::v4();
        $this->sessionRun($project, $session, '2026-09-23 10:00:00');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $project, (string) $session, $raw, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->usageRows());
    }

    /** The bridge reads a 404 with no error code as a server with no usage endpoint. */
    public function test_it_is_absent_with_no_error_code_while_push_is_switched_off(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'session-usage-flag@example.com');
        $project = $this->project($em, $owner, 'Session Usage Flag');
        $raw = $this->agentToken($client, $owner);
        $em->getConnection()->executeStatement("UPDATE feature_flag SET value = 'false' WHERE name = ?", [AgentPush::FLAG]);

        $this->put($client, $project, (string) Uuid::v4(), $raw, ['processes' => [self::process('reported', 'claude')]]);

        self::assertResponseStatusCodeSame(404);
        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('project_not_found', $body);
        self::assertStringNotContainsString('process_count_mismatch', $body);
    }

    public function test_a_widget_token_is_refused_by_the_firewall(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'session-usage-widget@example.com');
        $project = $this->project($em, $owner, 'Session Usage Widget');
        $session = Uuid::v4();
        $this->sessionRun($project, $session, '2026-09-23 10:00:00');
        $raw = AgentCredential::tokenFor(static::getContainer(), $owner, 'site-review', $project);

        $this->put($client, $project, (string) $session, $raw, ['processes' => [self::process('reported', 'claude')]]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->usageRows());
    }

    public function test_usage_reports_share_the_run_report_limit(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('limiter.agent_worker_runs', new RateLimiterFactory(
            ['id' => 'agent_worker_runs', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
        $em = $this->em();
        $owner = $this->user($em, 'session-usage-limit@example.com');
        $project = $this->project($em, $owner, 'Session Usage Limit');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $project, (string) Uuid::v4(), $raw, ['processes' => [self::process('reported', 'claude')]]);
        self::assertResponseStatusCodeSame(409);

        $this->put($client, $project, (string) Uuid::v4(), $raw, ['processes' => [self::process('reported', 'claude')]]);
        self::assertResponseStatusCodeSame(429);
    }

    /** @return array<string, mixed> */
    private static function process(string $source, string $model): array
    {
        return ['source' => $source, 'models' => [$model => [
            'inputTokens' => 10, 'outputTokens' => 20, 'cacheReadTokens' => 30, 'cacheWriteTokens' => 40, 'costUsd' => 0.25,
        ]]];
    }

    private function sessionRun(Project $project, Uuid $session, ?string $startedAt, WorkerRunKind $kind = WorkerRunKind::Worker): WorkerRun
    {
        $em = $this->em();
        $run = $this->seedRun($em, $project, kind: $kind);
        $run->sessionId = $session;
        $run->startedAt = null === $startedAt ? null : new \DateTimeImmutable($startedAt);
        $em->flush();

        return $run;
    }

    /** @param array<string, mixed> $body */
    private function put(KernelBrowser $client, Project $project, string $sessionId, string $raw, array $body): void
    {
        $client->request(
            Request::METHOD_PUT,
            '/api/projects/'.$project->id.'/worker-runs/sessions/'.$sessionId.'/usage',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    /** @return list<array{string, string}> the run and the model of each usage row, in start order */
    private function usageRows(): array
    {
        /** @var list<array{run_id: string, model: string}> $rows */
        $rows = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT u.run_id, u.model FROM bridge_worker_run_usage u JOIN bridge_worker_runs r ON r.id = u.run_id ORDER BY r.started_at',
        );

        return array_map(static fn (array $row): array => [$row['run_id'], $row['model']], $rows);
    }

    private function runOf(WorkerRun $run): WorkerRun
    {
        $this->em()->clear();

        return $this->em()->find(WorkerRun::class, $run->id) ?? throw new \LogicException('The run is gone.');
    }
}
