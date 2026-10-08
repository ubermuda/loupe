<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Service\InteractiveRuns;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
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

final class InteractiveLaunchApiTest extends WebTestCase
{
    use BridgeScenario;

    private const string BRIDGE_ID = '0199a0e2-9d4c-7c5e-9f2a-3b1c6d7e8f90';

    private const string CARD_ID = '0199a0e2-b1f3-7a44-9c11-2d3e4f506172';

    public function test_a_launch_that_runs_opens_an_interactive_run_of_the_bridge(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'launch-running@example.com');
        $project = $this->project($em, $owner, 'Launch Running');
        $raw = $this->agentToken($client, $owner);
        $sessionId = (string) Uuid::v4();

        $this->put($client, $this->path($project->id, $sessionId), $raw, $this->payload(['cardNumber' => 7, 'workKind' => 'Pair on design']));

        self::assertResponseStatusCodeSame(201);
        $run = $this->onlyRun();
        self::assertSame($this->idOf($client), (string) $run->id);
        self::assertSame(WorkerRunKind::Interactive, $run->kind);
        self::assertSame(WorkerRunState::Running, $run->state);
        self::assertSame($sessionId, (string) $run->sessionId);
        self::assertSame(self::BRIDGE_ID, (string) $run->bridgeId);
        self::assertSame(self::CARD_ID, (string) $run->subjectId);
        self::assertSame(7, $run->cardNumber);
        self::assertSame('Pair on design', $run->workKind);
        self::assertNull($run->workRequestId);
        self::assertNull($run->ruleId);
        self::assertNull($run->runKey);
    }

    public function test_a_launch_of_a_work_request_stores_the_request(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'launch-work-request@example.com');
        $project = $this->project($em, $owner, 'Launch Work Request');
        $raw = $this->agentToken($client, $owner);
        $workRequestId = (string) Uuid::v7();

        $this->put($client, $this->path($project->id, (string) Uuid::v4()), $raw, $this->payload(['workKind' => 'design', 'workRequestId' => $workRequestId, 'ruleId' => 'design-on-entry']));

        self::assertResponseStatusCodeSame(201);
        $run = $this->onlyRun();
        self::assertSame('design', $run->workKind);
        self::assertSame($workRequestId, $run->workRequestId?->toRfc4122());
        self::assertSame('design-on-entry', $run->ruleId);
    }

    public function test_a_launch_stores_its_harness_fields(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'launch-harness@example.com');
        $project = $this->project($em, $owner, 'Launch Harness');
        $raw = $this->agentToken($client, $owner);
        $harness = ['harness' => 'codex', 'account' => 'work', 'model' => 'gpt-5', 'harnessSessionId' => 'thread-1'];

        $this->put($client, $this->path($project->id, (string) Uuid::v4()), $raw, $this->payload($harness));
        self::assertResponseStatusCodeSame(201);
        $this->put($client, $this->path($project->id, (string) Uuid::v4()), $raw, $this->payload([...$harness, 'state' => 'not-started', 'failureReason' => 'codex exited 1']));
        self::assertResponseStatusCodeSame(201);

        $runs = $this->allRuns();
        self::assertCount(2, $runs);
        foreach ($runs as $run) {
            self::assertSame(['codex', 'work', 'gpt-5', 'thread-1'], [$run->harness, $run->account, $run->model, $run->harnessSessionId]);
        }
    }

    /** A null keeps a stored value, and a retry can carry a value the first report did not. */
    public function test_a_retry_fills_the_harness_fields_of_the_run_and_a_null_keeps_them(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'launch-harness-retry@example.com');
        $project = $this->project($em, $owner, 'Launch Harness Retry');
        $raw = $this->agentToken($client, $owner);
        $path = $this->path($project->id, (string) Uuid::v4());

        $this->put($client, $path, $raw, $this->payload(['harness' => 'codex', 'account' => 'work']));
        self::assertResponseStatusCodeSame(201);
        $this->put($client, $path, $raw, $this->payload(['harnessSessionId' => 'thread-1']));
        self::assertResponseStatusCodeSame(200);

        $run = $this->onlyRun();
        self::assertSame(['codex', 'work', null, 'thread-1'], [$run->harness, $run->account, $run->model, $run->harnessSessionId]);
    }

    public function test_a_retry_of_a_running_launch_answers_200_with_the_same_run(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'launch-retry@example.com');
        $project = $this->project($em, $owner, 'Launch Retry');
        $raw = $this->agentToken($client, $owner);
        $path = $this->path($project->id, (string) Uuid::v4());

        $this->put($client, $path, $raw, $this->payload());
        self::assertResponseStatusCodeSame(201);
        $first = $this->idOf($client);

        $this->put($client, $path, $raw, $this->payload());

        self::assertResponseStatusCodeSame(200);
        self::assertSame($first, $this->idOf($client));
        $this->onlyRun();
    }

    /** A retry that arrives after a move closed the session must not open it again. */
    public function test_a_running_launch_of_a_session_that_has_a_run_changes_nothing(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'launch-after-close@example.com');
        $project = $this->project($em, $owner, 'Launch After Close');
        $raw = $this->agentToken($client, $owner);
        $sessionId = (string) Uuid::v4();
        $path = $this->path($project->id, $sessionId);

        $this->put($client, $path, $raw, $this->payload());
        self::assertResponseStatusCodeSame(201);
        $first = $this->idOf($client);
        $interactiveRuns = static::getContainer()->get(InteractiveRuns::class);
        self::assertInstanceOf(InteractiveRuns::class, $interactiveRuns);
        $interactiveRuns->closeOnMove($this->em()->find(Project::class, $project->id) ?? throw new \LogicException('The project exists.'), [Uuid::fromString(self::CARD_ID)]);

        $this->put($client, $path, $raw, $this->payload());

        self::assertResponseStatusCodeSame(200);
        self::assertSame($first, $this->idOf($client));
        self::assertSame(WorkerRunState::Closed, $this->onlyRun()->state);

        $failedSession = (string) Uuid::v4();
        $this->put($client, $this->path($project->id, $failedSession), $raw, $this->payload(['state' => 'not-started', 'failureReason' => 'launcher exited 1']));
        self::assertResponseStatusCodeSame(201);
        $failed = $this->idOf($client);

        $this->put($client, $this->path($project->id, $failedSession), $raw, $this->payload());

        self::assertResponseStatusCodeSame(200);
        self::assertSame($failed, $this->idOf($client));
        self::assertCount(2, $this->allRuns());
    }

    public function test_a_session_that_opened_its_own_run_first_keeps_it(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'launch-after-open@example.com');
        $project = $this->project($em, $owner, 'Launch After Open');
        $raw = $this->agentToken($client, $owner);
        $sessionId = Uuid::v4();
        $interactiveRuns = static::getContainer()->get(InteractiveRuns::class);
        self::assertInstanceOf(InteractiveRuns::class, $interactiveRuns);
        $open = $interactiveRuns->open($project, Uuid::fromString(self::CARD_ID), 1, $sessionId, 'card_run_open');

        $this->put($client, $this->path($project->id, (string) $sessionId), $raw, $this->payload(['harness' => 'claude-code']));

        self::assertResponseStatusCodeSame(200);
        self::assertSame((string) $open->id, $this->idOf($client));
        self::assertSame('claude-code', $this->onlyRun()->harness);
    }

    public function test_a_launch_that_failed_records_a_run_that_never_started(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'launch-failed@example.com');
        $project = $this->project($em, $owner, 'Launch Failed');
        $raw = $this->agentToken($client, $owner);
        $path = $this->path($project->id, (string) Uuid::v4());
        $payload = $this->payload(['state' => 'not-started', 'at' => '2026-09-23T10:00:00-04:00', 'failureReason' => '  launcher exited 127  ']);

        $this->put($client, $path, $raw, $payload);

        self::assertResponseStatusCodeSame(201);
        $run = $this->onlyRun();
        self::assertSame(WorkerRunKind::Interactive, $run->kind);
        self::assertSame(WorkerRunState::NotStarted, $run->state);
        self::assertSame('launcher exited 127', $run->failureReason);
        self::assertSame(self::BRIDGE_ID, (string) $run->bridgeId);
        self::assertSame('2026-09-23T14:00:00+00:00', $run->endedAt?->format(\DateTimeInterface::ATOM));

        $this->put($client, $path, $raw, $payload);

        self::assertResponseStatusCodeSame(200);
        self::assertSame((string) $run->id, $this->idOf($client));
        $this->onlyRun();
    }

    public function test_another_users_project_answers_project_not_found(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $caller = $this->user($em, 'launch-caller@example.com');
        $other = $this->project($em, $this->user($em, 'launch-other@example.com'), 'Launch Private');
        $raw = $this->agentToken($client, $caller);

        foreach ([(string) $other->id, (string) Uuid::v7()] as $handle) {
            $this->put($client, '/api/projects/'.$handle.'/interactive-runs/'.Uuid::v4(), $raw, $this->payload());

            self::assertResponseStatusCodeSame(404, $handle);
            self::assertJsonStringEqualsJsonString('{"error":"project_not_found"}', (string) $client->getResponse()->getContent());
        }

        self::assertSame([], $this->allRuns());
    }

    public function test_a_session_id_that_is_not_a_uuid_is_refused_with_a_code(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'launch-session-id@example.com');
        $project = $this->project($em, $owner, 'Launch Session Id');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $this->path($project->id, 'not-a-uuid'), $raw, $this->payload());

        self::assertResponseStatusCodeSame(422);
        self::assertJsonStringEqualsJsonString('{"error":"invalid_session_id"}', (string) $client->getResponse()->getContent());
        self::assertSame([], $this->allRuns());
    }

    /** The bridge reads a 404 with no error code as a server with no launch endpoint. */
    public function test_it_is_absent_with_no_error_code_while_push_is_switched_off(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'launch-flag@example.com');
        $project = $this->project($em, $owner, 'Launch Flag');
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
        yield 'a state a launch never reports' => [['state' => 'queued']];
        yield 'a missing state' => [['state' => null]];
        yield 'a missing moment' => [['at' => null]];
        yield 'a missing bridge id' => [['bridgeId' => null]];
        yield 'a bridge id that is not a uuid' => [['bridgeId' => 'nope']];
        yield 'a missing subject type' => [['subjectType' => null]];
        yield 'a subject that is no card, because an interactive run is about a card' => [['subjectType' => 'analysis']];
        yield 'a missing subject id' => [['subjectId' => null]];
        yield 'a subject id that is not a uuid' => [['subjectId' => 'nope']];
        yield 'a missing card number' => [['cardNumber' => null]];
        yield 'a card number of zero' => [['cardNumber' => 0]];
        yield 'a blank work kind' => [['workKind' => ' ']];
        yield 'a work kind above the limit' => [['workKind' => str_repeat('x', WorkerRun::MAX_WORK_KIND_LENGTH + 1)]];
        yield 'a work request id that is not a uuid' => [['workRequestId' => 'nope']];
        yield 'a rule id with a space' => [['ruleId' => 'not a rule']];
        yield 'not-started with no reason' => [['state' => 'not-started']];
        yield 'not-started with a blank reason' => [['state' => 'not-started', 'failureReason' => '  ']];
        yield 'a reason above the limit' => [['state' => 'not-started', 'failureReason' => str_repeat('x', WorkerRun::MAX_FAILURE_REASON_LENGTH + 1)]];
        yield 'running with a reason' => [['failureReason' => 'launcher exited 1']];
        yield 'a harness with a space and capitals' => [['harness' => 'Claude Code']];
        yield 'an account with a slash' => [['account' => 'home/geoffrey']];
        yield 'a model with a newline' => [['model' => "gpt\n5"]];
        yield 'a model above the limit' => [['model' => str_repeat('m', WorkerRun::MAX_MODEL_LENGTH + 1)]];
        yield 'a harness session id with a space' => [['harnessSessionId' => 'thread 1']];
        yield 'a blank harness session id' => [['harnessSessionId' => '']];
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
        $owner = $this->user($em, 'launch-invalid-'.$suffix.'@example.com');
        $project = $this->project($em, $owner, 'Launch Invalid '.substr($suffix, 0, 6));
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $this->path($project->id, (string) Uuid::v4()), $raw, $this->payload($overrides));

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->allRuns());
    }

    public function test_a_widget_token_is_refused_by_the_firewall(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'launch-widget@example.com');
        $project = $this->project($em, $owner, 'Launch Widget');
        $raw = AgentCredential::tokenFor(static::getContainer(), $owner, 'site-review', $project);

        $this->put($client, $this->path($project->id, (string) Uuid::v4()), $raw, $this->payload());

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->allRuns());
    }

    public function test_launch_reports_share_the_run_report_limit(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('limiter.agent_worker_runs', new RateLimiterFactory(
            ['id' => 'agent_worker_runs', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
        $em = $this->em();
        $owner = $this->user($em, 'launch-limit@example.com');
        $project = $this->project($em, $owner, 'Launch Limit');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $this->path($project->id, (string) Uuid::v4()), $raw, $this->payload());
        self::assertResponseStatusCodeSame(201);

        $this->put($client, $this->path($project->id, (string) Uuid::v4()), $raw, $this->payload());
        self::assertResponseStatusCodeSame(429);
    }

    private function path(?Uuid $projectId, string $sessionId): string
    {
        return '/api/projects/'.$projectId.'/interactive-runs/'.$sessionId;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'bridgeId' => self::BRIDGE_ID,
            'subjectType' => 'card',
            'subjectId' => self::CARD_ID,
            'cardNumber' => 1,
            'workKind' => 'design',
            'state' => 'running',
            'at' => '2026-09-23T10:00:00+00:00',
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
}
