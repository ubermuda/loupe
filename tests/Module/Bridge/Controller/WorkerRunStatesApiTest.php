<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\Repository\WorkerRunStateChangeRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
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

final class WorkerRunStatesApiTest extends WebTestCase
{
    use BridgeScenario;

    public function test_the_first_state_of_an_unknown_run_creates_the_run(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-states-create@example.com');
        $project = $this->project($em, $owner, 'Run States Create');
        $raw = $this->agentToken($client, $owner);
        $runId = (string) Uuid::v4();
        $cardId = (string) Uuid::v7();

        $this->put($client, $this->path($project->id, $runId), $raw, $this->payload(['cardId' => $cardId, 'cardNumber' => 7]));

        self::assertResponseStatusCodeSame(201);
        $run = $this->onlyRun();
        self::assertSame($this->idOf($client), (string) $run->id);
        self::assertSame($runId, (string) $run->runKey);
        self::assertSame($cardId, (string) $run->cardId);
        self::assertSame(7, $run->cardNumber);
        self::assertSame(WorkerRunState::Queued, $run->state);
        self::assertNull($run->startedAt);
    }

    /** A retry of a report whose response the bridge never saw holds a state the run already has. */
    public function test_a_state_the_run_already_holds_answers_200(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-states-repeat@example.com');
        $project = $this->project($em, $owner, 'Run States Repeat');
        $raw = $this->agentToken($client, $owner);
        $path = $this->path($project->id, (string) Uuid::v4());
        $payload = $this->payload();

        $this->put($client, $path, $raw, $payload);
        self::assertResponseStatusCodeSame(201);
        $first = $this->idOf($client);

        $this->put($client, $path, $raw, $payload);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($first, $this->idOf($client));
        self::assertCount(1, $this->historyOf($this->onlyRun()));
    }

    public function test_a_run_moves_through_its_states_and_keeps_each_one(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-states-sequence@example.com');
        $project = $this->project($em, $owner, 'Run States Sequence');
        $raw = $this->agentToken($client, $owner);
        $path = $this->path($project->id, (string) Uuid::v4());
        $sessionId = (string) Uuid::v4();

        $this->put($client, $path, $raw, $this->payload());
        $this->put($client, $path, $raw, $this->payload([
            'state' => 'running',
            'at' => '2026-09-23T10:00:05+00:00',
            'sessionId' => $sessionId,
            'startedAt' => '2026-09-23T10:00:05+00:00',
        ]));
        self::assertResponseStatusCodeSame(201);
        $this->put($client, $path, $raw, $this->payload([
            'state' => 'failed',
            'at' => '2026-09-23T10:01:00-04:00',
            'sessionId' => $sessionId,
            'startedAt' => '2026-09-23T10:00:05+00:00',
            'endedAt' => '2026-09-23T10:01:00-04:00',
            'exitCode' => 2,
            'failureReason' => null,
            'output' => 'boom',
        ]));
        self::assertResponseStatusCodeSame(201);

        $run = $this->onlyRun();
        self::assertSame(WorkerRunState::Failed, $run->state);
        self::assertSame($sessionId, (string) $run->sessionId);
        self::assertSame(2, $run->exitCode);
        self::assertSame('boom', $run->output);
        self::assertSame('2026-09-23T14:01:00+00:00', $run->endedAt?->format(\DateTimeInterface::ATOM));
        self::assertSame(
            [['queued', '2026-09-23T10:00:00+00:00'], ['running', '2026-09-23T10:00:05+00:00'], ['failed', '2026-09-23T14:01:00+00:00']],
            array_map(
                static fn (WorkerRunStateChange $change): array => [$change->state->value, $change->at->format(\DateTimeInterface::ATOM)],
                $this->historyOf($run),
            ),
        );
    }

    public function test_another_users_project_answers_project_not_found(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $caller = $this->user($em, 'run-states-caller@example.com');
        $other = $this->project($em, $this->user($em, 'run-states-other@example.com'), 'Run States Private');
        $raw = $this->agentToken($client, $caller);

        foreach ([(string) $other->id, (string) Uuid::v7()] as $handle) {
            $this->put($client, '/api/projects/'.$handle.'/worker-runs/'.Uuid::v4(), $raw, $this->payload());

            self::assertResponseStatusCodeSame(404, $handle);
            self::assertJsonStringEqualsJsonString('{"error":"project_not_found"}', (string) $client->getResponse()->getContent());
        }

        self::assertSame([], $this->allRuns());
    }

    /** The bridge reads a 404 with no error code as a server with no run state endpoint. */
    public function test_it_is_absent_with_no_error_code_while_push_is_switched_off(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-states-flag@example.com');
        $project = $this->project($em, $owner, 'Run States Flag');
        $raw = $this->agentToken($client, $owner);
        $em->getConnection()->executeStatement("UPDATE feature_flag SET value = 'false' WHERE name = ?", [AgentPush::FLAG]);

        $this->put($client, $this->path($project->id, (string) Uuid::v4()), $raw, $this->payload());

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('project_not_found', (string) $client->getResponse()->getContent());
        self::assertSame([], $this->allRuns());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidPayloads(): iterable
    {
        $outcome = [
            'sessionId' => (string) Uuid::v4(),
            'startedAt' => '2026-09-23T10:00:00+00:00',
            'endedAt' => '2026-09-23T10:01:00+00:00',
            'exitCode' => 0,
            'failureReason' => null,
            'output' => '',
        ];

        yield 'an unknown state' => [['state' => 'paused']];
        yield 'a timed-out state, which only the server infers' => [['state' => 'timed-out']];
        yield 'a lost state, which only the server infers' => [['state' => 'lost']];
        yield 'a missing moment' => [['at' => null]];
        yield 'a bridge id that is not a uuid' => [['bridgeId' => 'nope']];
        yield 'a card number of zero' => [['cardNumber' => 0]];
        yield 'a blank rule name' => [['ruleName' => ' ']];
        yield 'running with no session' => [['state' => 'running', 'startedAt' => '2026-09-23T10:00:00+00:00']];
        yield 'running with no start' => [['state' => 'running', 'sessionId' => (string) Uuid::v4()]];
        yield 'an outcome with no end' => [array_merge($outcome, ['state' => 'succeeded', 'endedAt' => null])];
        yield 'an outcome with no output' => [array_merge($outcome, ['state' => 'succeeded', 'output' => null])];
        yield 'succeeded with a non-zero exit code' => [array_merge($outcome, ['state' => 'succeeded', 'exitCode' => 1])];
        yield 'failed with a zero exit code' => [array_merge($outcome, ['state' => 'failed'])];
        yield 'not-started with an exit code' => [array_merge($outcome, ['state' => 'not-started', 'failureReason' => 'no claude'])];
        yield 'not-started with no reason' => [array_merge($outcome, ['state' => 'not-started', 'exitCode' => null])];
        yield 'an end before the start' => [array_merge($outcome, ['state' => 'succeeded', 'endedAt' => '2026-09-23T09:00:00+00:00'])];
        yield 'a drop reason the bridge does not send' => [['state' => 'dropped', 'reason' => 'bored']];
        yield 'a replacement that is not a uuid' => [['state' => 'replaced', 'replacedBy' => 'nope']];
        yield 'a chain cap of zero' => [['state' => 'waiting-for-person', 'maxChain' => 0]];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalidPayloads')]
    public function test_an_invalid_body_is_refused(array $overrides): void
    {
        $client = static::createClient();
        $em = $this->em();
        $suffix = md5(serialize($overrides));
        $owner = $this->user($em, 'run-states-invalid-'.$suffix.'@example.com');
        $project = $this->project($em, $owner, 'Run States Invalid '.substr($suffix, 0, 6));
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $this->path($project->id, (string) Uuid::v4()), $raw, $this->payload($overrides));

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->allRuns());
    }

    public function test_a_run_id_that_is_not_a_uuid_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-states-run-id@example.com');
        $project = $this->project($em, $owner, 'Run States Run Id');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $this->path($project->id, 'not-a-uuid'), $raw, $this->payload());

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->allRuns());
    }

    public function test_a_widget_token_is_refused_by_the_firewall(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-states-widget@example.com');
        $project = $this->project($em, $owner, 'Run States Widget');
        $raw = AgentCredential::tokenFor(static::getContainer(), $owner, 'site-review', $project);

        $this->put($client, $this->path($project->id, (string) Uuid::v4()), $raw, $this->payload());

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->allRuns());
    }

    public function test_state_reports_share_the_run_report_limit(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('limiter.agent_worker_runs', new RateLimiterFactory(
            ['id' => 'agent_worker_runs', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
        $em = $this->em();
        $owner = $this->user($em, 'run-states-limit@example.com');
        $project = $this->project($em, $owner, 'Run States Limit');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $this->path($project->id, (string) Uuid::v4()), $raw, $this->payload());
        self::assertResponseStatusCodeSame(201);

        $this->put($client, $this->path($project->id, (string) Uuid::v4()), $raw, $this->payload());
        self::assertResponseStatusCodeSame(429);
    }

    private function path(?Uuid $projectId, string $runId): string
    {
        return '/api/projects/'.$projectId.'/worker-runs/'.$runId;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'bridgeId' => '0199a0e2-9d4c-7c5e-9f2a-3b1c6d7e8f90',
            'state' => 'queued',
            'at' => '2026-09-23T10:00:00+00:00',
            'cardId' => '0199a0e2-b1f3-7a44-9c11-2d3e4f506172',
            'cardNumber' => 1,
            'ruleName' => 'plan',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function put(KernelBrowser $client, string $path, string $raw, array $payload): void
    {
        $client->request(
            Request::METHOD_PUT,
            $path,
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
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
        $this->em()->clear();
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

    /** @return list<WorkerRunStateChange> */
    private function historyOf(WorkerRun $run): array
    {
        $repository = static::getContainer()->get(WorkerRunStateChangeRepository::class);
        self::assertInstanceOf(WorkerRunStateChangeRepository::class, $repository);

        return $repository->findForRun($run);
    }
}
