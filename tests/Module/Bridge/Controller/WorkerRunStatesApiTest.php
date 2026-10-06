<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\Repository\WorkerRunStateChangeRepository;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunReason;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
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
        self::assertSame($cardId, (string) $run->subjectId);
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

    /** A clean exit with no result line closes as no-result and keeps the flag. */
    public function test_a_no_result_outcome_stores_the_result_flag(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-states-no-result@example.com');
        $project = $this->project($em, $owner, 'Run States No Result');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $this->path($project->id, (string) Uuid::v4()), $raw, $this->payload([
            'state' => 'no-result',
            'sessionId' => (string) Uuid::v4(),
            'startedAt' => '2026-09-23T10:00:00+00:00',
            'endedAt' => '2026-09-23T10:01:00+00:00',
            'exitCode' => 0,
            'hasResult' => false,
            'failureReason' => null,
            'output' => '',
        ]));

        self::assertResponseStatusCodeSame(201);
        $run = $this->onlyRun();
        self::assertSame(WorkerRunState::NoResult, $run->state);
        self::assertSame(0, $run->exitCode);
        self::assertFalse($run->hasResult);
    }

    public function test_a_resume_that_gives_up_stores_its_link_and_its_result(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-states-gave-up@example.com');
        $project = $this->project($em, $owner, 'Run States Gave Up');
        $raw = $this->agentToken($client, $owner);
        $first = (string) Uuid::v4();
        $resume = (string) Uuid::v4();

        $this->put($client, $this->path($project->id, $first), $raw, $this->payload());
        $this->put($client, $this->path($project->id, $resume), $raw, $this->payload([
            'continues' => $first,
        ]));
        self::assertResponseStatusCodeSame(201);
        $this->put($client, $this->path($project->id, $resume), $raw, $this->payload([
            'state' => 'gave-up',
            'sessionId' => (string) Uuid::v4(),
            'startedAt' => '2026-09-23T10:00:00+00:00',
            'endedAt' => '2026-09-23T10:01:00+00:00',
            'exitCode' => 0,
            'hasResult' => true,
            'resultStatus' => 'unfinished',
            'resultFields' => ['pullRequest' => 'https://example.com/pull/1'],
            'resumeSkipped' => 'card_moved',
            'output' => 'CI still runs',
        ]));

        self::assertResponseStatusCodeSame(201);
        $runs = $this->allRuns();
        $byKey = array_combine(array_map(static fn (WorkerRun $run): string => (string) $run->runKey, $runs), $runs);
        $run = $byKey[$resume];
        self::assertSame(WorkerRunState::GaveUp, $run->state);
        self::assertSame((string) $byKey[$first]->id, (string) $run->continuesRun?->id);
        self::assertSame('unfinished', $run->resultStatus);
        self::assertSame(['pullRequest' => 'https://example.com/pull/1'], $run->resultFields);
        self::assertSame('card_moved', $run->resumeSkipped);
    }

    public function test_a_blocked_worker_closes_as_blocked(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-states-blocked@example.com');
        $project = $this->project($em, $owner, 'Run States Blocked');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $this->path($project->id, (string) Uuid::v4()), $raw, $this->payload([
            'state' => 'blocked',
            'sessionId' => (string) Uuid::v4(),
            'startedAt' => '2026-09-23T10:00:00+00:00',
            'endedAt' => '2026-09-23T10:01:00+00:00',
            'exitCode' => 0,
            'hasResult' => true,
            'resultStatus' => 'blocked',
            'output' => 'needs a decision',
        ]));

        self::assertResponseStatusCodeSame(201);
        $run = $this->onlyRun();
        self::assertSame(WorkerRunState::Blocked, $run->state);
        self::assertSame('blocked', $run->resultStatus);
        self::assertNull($run->resultFields);
        self::assertNull($run->resultReason);
    }

    /** @return iterable<string, array{array<string, mixed>, ?WorkerRunReason}> */
    public static function reportedReasons(): iterable
    {
        yield 'a known code' => [['resultReason' => 'stacked'], WorkerRunReason::Stacked];
        yield 'a code the server does not know' => [['resultReason' => 'rate-limited'], WorkerRunReason::Other];
        yield 'an empty code' => [['resultReason' => ''], null];
        yield 'no code' => [[], null];
    }

    /** @param array<string, mixed> $reason */
    #[DataProvider('reportedReasons')]
    public function test_an_outcome_stores_the_reason_the_bridge_reports(array $reason, ?WorkerRunReason $expected): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-states-reason@example.com');
        $project = $this->project($em, $owner, 'Run States Reason');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $this->path($project->id, (string) Uuid::v4()), $raw, $this->payload([
            'state' => 'blocked',
            'sessionId' => (string) Uuid::v4(),
            'startedAt' => '2026-09-23T10:00:00+00:00',
            'endedAt' => '2026-09-23T10:01:00+00:00',
            'exitCode' => 0,
            'hasResult' => true,
            'resultStatus' => 'blocked',
            'output' => 'stacked on another pull request',
            ...$reason,
        ]));

        self::assertResponseStatusCodeSame(201);
        self::assertSame($expected, $this->onlyRun()->resultReason);
    }

    public function test_a_worker_that_waits_on_the_forge_closes_as_waiting_on_forge(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-states-forge@example.com');
        $project = $this->project($em, $owner, 'Run States Forge');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $this->path($project->id, (string) Uuid::v4()), $raw, $this->payload([
            'state' => 'waiting-on-forge',
            'sessionId' => (string) Uuid::v4(),
            'startedAt' => '2026-09-23T10:00:00+00:00',
            'endedAt' => '2026-09-23T10:01:00+00:00',
            'exitCode' => 0,
            'hasResult' => true,
            'resultStatus' => 'waiting',
            'output' => 'checks run on the pull request',
        ]));

        self::assertResponseStatusCodeSame(201);
        $run = $this->onlyRun();
        self::assertSame(WorkerRunState::WaitingOnForge, $run->state);
        self::assertSame('waiting', $run->resultStatus);
    }

    public function test_an_outcome_stores_its_usage(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-states-usage@example.com');
        $project = $this->project($em, $owner, 'Run States Usage');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $this->path($project->id, (string) Uuid::v4()), $raw, $this->payload(array_merge(self::outcome(), [
            'usage' => ['source' => 'estimated', 'models' => [
                'claude-opus-5-5' => ['inputTokens' => 1200, 'outputTokens' => 340, 'cacheReadTokens' => 56000, 'cacheWriteTokens' => 7800, 'costUsd' => 0.4321],
                'claude-unpriced' => ['inputTokens' => 5, 'outputTokens' => 6, 'cacheReadTokens' => 0, 'cacheWriteTokens' => 0, 'costUsd' => null],
                // Go writes a whole float64 as an integer.
                'claude-whole-dollars' => ['inputTokens' => 7, 'outputTokens' => 8, 'cacheReadTokens' => 0, 'cacheWriteTokens' => 0, 'costUsd' => 2],
            ]],
        ])));

        self::assertResponseStatusCodeSame(201);
        $run = $this->onlyRun();
        self::assertSame(WorkerRunUsageSource::Estimated, $run->usageSource);
        self::assertSame([
            ['model' => 'claude-opus-5-5', 'input_tokens' => 1200, 'output_tokens' => 340, 'cache_read_tokens' => 56000, 'cache_write_tokens' => 7800, 'cost_usd' => '0.432100'],
            ['model' => 'claude-unpriced', 'input_tokens' => 5, 'output_tokens' => 6, 'cache_read_tokens' => 0, 'cache_write_tokens' => 0, 'cost_usd' => null],
            ['model' => 'claude-whole-dollars', 'input_tokens' => 7, 'output_tokens' => 8, 'cache_read_tokens' => 0, 'cache_write_tokens' => 0, 'cost_usd' => '2.000000'],
        ], $this->em()->getConnection()->fetchAllAssociative(
            'SELECT model, input_tokens, output_tokens, cache_read_tokens, cache_write_tokens, cost_usd FROM bridge_worker_run_usage ORDER BY model',
        ));
    }

    /** The bridge sends usage with an outcome alone, so the server checks it on any state and stores it from an outcome. */
    public function test_an_open_state_ignores_its_usage(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-states-usage-open@example.com');
        $project = $this->project($em, $owner, 'Run States Usage Open');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $this->path($project->id, (string) Uuid::v4()), $raw, $this->payload([
            'usage' => ['source' => 'reported', 'models' => []],
        ]));

        self::assertResponseStatusCodeSame(201);
        self::assertNull($this->onlyRun()->usageSource);
    }

    public function test_the_worker_pool_is_stored(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-states-pool@example.com');
        $project = $this->project($em, $owner, 'Run States Pool');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $this->path($project->id, (string) Uuid::v4()), $raw, $this->payload(['workerPool' => 'quick-2']));

        self::assertResponseStatusCodeSame(201);
        self::assertSame('quick-2', $this->onlyRun()->workerPool);
    }

    public function test_the_experiment_of_the_run_is_stored(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-states-experiment@example.com');
        $project = $this->project($em, $owner, 'Run States Experiment');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $this->path($project->id, (string) Uuid::v4()), $raw, $this->payload([
            'experiment' => 'plan-model',
            'variant' => 'opus_4',
            'requestedModel' => 'claude-opus-4',
            'switchedFrom' => 'sonnet',
        ]));

        self::assertResponseStatusCodeSame(201);
        $run = $this->onlyRun();
        self::assertSame(
            ['plan-model', 'opus_4', 'claude-opus-4', 'sonnet'],
            [$run->experiment, $run->variant, $run->requestedModel, $run->switchedFrom],
        );
    }

    public function test_a_person_stops_a_run_the_bridge_resumed_for_them(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-states-stopped@example.com');
        $project = $this->project($em, $owner, 'Run States Stopped');
        $raw = $this->agentToken($client, $owner);
        $path = $this->path($project->id, (string) Uuid::v4());
        $start = [
            'sessionId' => (string) Uuid::v4(),
            'startedAt' => '2026-09-23T10:00:05+00:00',
        ];

        $this->put($client, $path, $raw, $this->payload(['state' => 'running', ...$start]));
        self::assertResponseStatusCodeSame(201);
        $this->put($client, $path, $raw, $this->payload(['state' => 'stopping', 'at' => '2026-09-23T10:02:00+00:00']));
        self::assertResponseStatusCodeSame(201);
        self::assertSame(WorkerRunState::Stopping, $this->onlyRun()->state);
        $this->put($client, $path, $raw, $this->payload([
            'state' => 'stopped',
            'at' => '2026-09-23T10:03:00+00:00',
            'endedAt' => '2026-09-23T10:02:30+00:00',
            'output' => 'stopped halfway',
            'usage' => ['source' => 'reported', 'models' => []],
            ...$start,
        ]));
        self::assertResponseStatusCodeSame(201);

        $run = $this->onlyRun();
        self::assertSame(WorkerRunState::Stopped, $run->state);
        self::assertSame('2026-09-23T10:02:30+00:00', $run->endedAt?->format(\DateTimeInterface::ATOM));
        self::assertSame('stopped halfway', $run->output);
        self::assertNull($run->exitCode);
        self::assertSame(WorkerRunUsageSource::Reported, $run->usageSource);
        self::assertSame(['running', 'stopping', 'stopped'], array_map(
            static fn (WorkerRunStateChange $change): string => $change->state->value,
            $this->historyOf($run),
        ));
    }

    public function test_a_stop_with_no_end_after_its_start_ends_at_its_moment(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-states-stopped-no-end@example.com');
        $project = $this->project($em, $owner, 'Run States Stopped No End');
        $raw = $this->agentToken($client, $owner);
        $path = $this->path($project->id, (string) Uuid::v4());

        $this->put($client, $path, $raw, $this->payload([
            'state' => 'stopped',
            'at' => '2026-09-23T10:00:05+00:00',
            'startedAt' => '2026-09-23T10:00:00+00:00',
        ]));

        self::assertResponseStatusCodeSame(201);
        self::assertSame('2026-09-23T10:00:05+00:00', $this->onlyRun()->endedAt?->format(\DateTimeInterface::ATOM));
    }

    public function test_a_queued_run_stops_with_no_start(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-states-stopped-queued@example.com');
        $project = $this->project($em, $owner, 'Run States Stopped Queued');
        $raw = $this->agentToken($client, $owner);
        $path = $this->path($project->id, (string) Uuid::v4());

        $this->put($client, $path, $raw, $this->payload());
        $this->put($client, $path, $raw, $this->payload(['state' => 'stopped', 'at' => '2026-09-23T10:01:00+00:00']));

        self::assertResponseStatusCodeSame(201);
        $run = $this->onlyRun();
        self::assertSame(WorkerRunState::Stopped, $run->state);
        self::assertNull($run->startedAt);
        self::assertSame('2026-09-23T10:01:00+00:00', $run->endedAt?->format(\DateTimeInterface::ATOM));
    }

    public function test_a_preparing_report_with_no_session_is_accepted(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-states-preparing@example.com');
        $project = $this->project($em, $owner, 'Run States Preparing');
        $raw = $this->agentToken($client, $owner);
        $path = $this->path($project->id, (string) Uuid::v4());

        $this->put($client, $path, $raw, $this->payload());
        $this->put($client, $path, $raw, $this->payload(['state' => 'preparing', 'at' => '2026-09-23T10:00:02+00:00']));

        self::assertResponseStatusCodeSame(201);
        $run = $this->onlyRun();
        self::assertSame(WorkerRunState::Preparing, $run->state);
        self::assertNull($run->sessionId);
        self::assertNull($run->startedAt);
    }

    public function test_a_preparing_run_moves_to_running(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-states-preparing-running@example.com');
        $project = $this->project($em, $owner, 'Run States Preparing Running');
        $raw = $this->agentToken($client, $owner);
        $path = $this->path($project->id, (string) Uuid::v4());
        $sessionId = (string) Uuid::v4();

        $this->put($client, $path, $raw, $this->payload());
        $this->put($client, $path, $raw, $this->payload(['state' => 'preparing', 'at' => '2026-09-23T10:00:02+00:00']));
        $this->put($client, $path, $raw, $this->payload([
            'state' => 'running',
            'at' => '2026-09-23T10:00:05+00:00',
            'sessionId' => $sessionId,
            'startedAt' => '2026-09-23T10:00:05+00:00',
        ]));

        self::assertResponseStatusCodeSame(201);
        $run = $this->onlyRun();
        self::assertSame(WorkerRunState::Running, $run->state);
        self::assertSame($sessionId, (string) $run->sessionId);
        self::assertSame(['queued', 'preparing', 'running'], array_map(
            static fn (WorkerRunStateChange $change): string => $change->state->value,
            $this->historyOf($run),
        ));
    }

    /** The before command failed, so the agent never started and the run has no session. */
    public function test_a_preparing_run_fails_with_no_session(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-states-preparing-failed@example.com');
        $project = $this->project($em, $owner, 'Run States Preparing Failed');
        $raw = $this->agentToken($client, $owner);
        $path = $this->path($project->id, (string) Uuid::v4());

        $this->put($client, $path, $raw, $this->payload());
        $this->put($client, $path, $raw, $this->payload(['state' => 'preparing', 'at' => '2026-09-23T10:00:02+00:00']));
        $this->put($client, $path, $raw, $this->payload([
            'state' => 'failed',
            'at' => '2026-09-23T10:00:04+00:00',
            'startedAt' => '2026-09-23T10:00:02+00:00',
            'endedAt' => '2026-09-23T10:00:04+00:00',
            'exitCode' => 1,
            'output' => 'before: npm ci failed',
        ]));

        self::assertResponseStatusCodeSame(201);
        $run = $this->onlyRun();
        self::assertSame(WorkerRunState::Failed, $run->state);
        self::assertNull($run->sessionId);
        self::assertSame(1, $run->exitCode);
        self::assertSame('before: npm ci failed', $run->output);
        self::assertSame('2026-09-23T10:00:02+00:00', $run->startedAt?->format(\DateTimeInterface::ATOM));
        self::assertSame(['queued', 'preparing', 'failed'], array_map(
            static fn (WorkerRunStateChange $change): string => $change->state->value,
            $this->historyOf($run),
        ));
    }

    public function test_a_command_run_succeeds_with_no_session(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-states-command@example.com');
        $project = $this->project($em, $owner, 'Run States Command');
        $raw = $this->agentToken($client, $owner);
        $path = $this->path($project->id, (string) Uuid::v4());
        $command = ['kind' => 'command', 'workKind' => 'sync'];

        $this->put($client, $path, $raw, $this->payload($command));
        self::assertResponseStatusCodeSame(201);
        $this->put($client, $path, $raw, $this->payload([
            ...$command,
            'state' => 'running',
            'at' => '2026-09-23T10:00:02+00:00',
            'startedAt' => '2026-09-23T10:00:02+00:00',
        ]));
        self::assertResponseStatusCodeSame(201);
        $this->put($client, $path, $raw, $this->payload([
            ...$command,
            'state' => 'succeeded',
            'at' => '2026-09-23T10:00:09+00:00',
            'startedAt' => '2026-09-23T10:00:02+00:00',
            'endedAt' => '2026-09-23T10:00:09+00:00',
            'exitCode' => 0,
            'output' => 'synced',
        ]));
        self::assertResponseStatusCodeSame(201);

        $run = $this->onlyRun();
        self::assertSame(WorkerRunKind::Command, $run->kind);
        self::assertSame(WorkerRunState::Succeeded, $run->state);
        self::assertNull($run->sessionId);
        self::assertSame(0, $run->exitCode);
        self::assertSame('synced', $run->output);
        self::assertSame(['queued', 'running', 'succeeded'], array_map(
            static fn (WorkerRunStateChange $change): string => $change->state->value,
            $this->historyOf($run),
        ));
    }

    /** A command that could not start or was killed reports exit code -1, and its output says why. */
    public function test_a_command_run_fails_with_exit_code_minus_one(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-states-command-failed@example.com');
        $project = $this->project($em, $owner, 'Run States Command Failed');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $this->path($project->id, (string) Uuid::v4()), $raw, $this->payload([
            'kind' => 'command',
            'state' => 'failed',
            'at' => '2026-09-23T10:00:04+00:00',
            'startedAt' => '2026-09-23T10:00:02+00:00',
            'endedAt' => '2026-09-23T10:00:04+00:00',
            'exitCode' => -1,
            'output' => 'exec: "sync-tool": executable file not found in $PATH',
        ]));

        self::assertResponseStatusCodeSame(201);
        $run = $this->onlyRun();
        self::assertSame(WorkerRunKind::Command, $run->kind);
        self::assertSame(WorkerRunState::Failed, $run->state);
        self::assertSame(-1, $run->exitCode);
        self::assertNull($run->failureReason);
        self::assertNull($run->sessionId);
    }

    /** A command prints no result line, so its result flag says nothing and a clean exit still succeeds. */
    public function test_a_command_run_succeeds_with_a_false_result_flag(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-states-command-flag@example.com');
        $project = $this->project($em, $owner, 'Run States Command Flag');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $this->path($project->id, (string) Uuid::v4()), $raw, $this->payload([
            'kind' => 'command',
            'state' => 'succeeded',
            'at' => '2026-09-23T10:00:09+00:00',
            'startedAt' => '2026-09-23T10:00:02+00:00',
            'endedAt' => '2026-09-23T10:00:09+00:00',
            'exitCode' => 0,
            'hasResult' => false,
            'output' => 'synced',
        ]));

        self::assertResponseStatusCodeSame(201);
        $run = $this->onlyRun();
        self::assertSame(WorkerRunState::Succeeded, $run->state);
        self::assertNull($run->hasResult);
    }

    /** The first report sets the kind, so a later report that names another kind leaves it. */
    public function test_a_later_report_keeps_the_kind_of_the_first(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-states-command-kind@example.com');
        $project = $this->project($em, $owner, 'Run States Command Kind');
        $raw = $this->agentToken($client, $owner);
        $path = $this->path($project->id, (string) Uuid::v4());

        $this->put($client, $path, $raw, $this->payload(['kind' => 'command']));
        $this->put($client, $path, $raw, $this->payload(['kind' => 'worker', 'state' => 'preparing', 'at' => '2026-09-23T10:00:02+00:00']));

        self::assertResponseStatusCodeSame(201);
        self::assertSame(WorkerRunKind::Command, $this->onlyRun()->kind);
    }

    public function test_a_run_with_no_kind_is_a_worker_run(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-states-no-kind@example.com');
        $project = $this->project($em, $owner, 'Run States No Kind');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $this->path($project->id, (string) Uuid::v4()), $raw, $this->payload());

        self::assertResponseStatusCodeSame(201);
        self::assertSame(WorkerRunKind::Worker, $this->onlyRun()->kind);
    }

    public function test_a_preparing_run_stops(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-states-preparing-stopped@example.com');
        $project = $this->project($em, $owner, 'Run States Preparing Stopped');
        $raw = $this->agentToken($client, $owner);
        $path = $this->path($project->id, (string) Uuid::v4());

        $this->put($client, $path, $raw, $this->payload(['state' => 'preparing', 'at' => '2026-09-23T10:00:02+00:00']));
        $this->put($client, $path, $raw, $this->payload(['state' => 'stopped', 'at' => '2026-09-23T10:00:30+00:00']));

        self::assertResponseStatusCodeSame(201);
        $run = $this->onlyRun();
        self::assertSame(WorkerRunState::Stopped, $run->state);
        self::assertNull($run->exitCode);
        self::assertSame('2026-09-23T10:00:30+00:00', $run->endedAt?->format(\DateTimeInterface::ATOM));
    }

    /** Reports can arrive out of order, and a late preparing report never moves a running run back. */
    public function test_a_late_preparing_report_leaves_a_running_run_running(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-states-preparing-late@example.com');
        $project = $this->project($em, $owner, 'Run States Preparing Late');
        $raw = $this->agentToken($client, $owner);
        $path = $this->path($project->id, (string) Uuid::v4());

        $this->put($client, $path, $raw, $this->payload([
            'state' => 'running',
            'at' => '2026-09-23T10:00:05+00:00',
            'sessionId' => (string) Uuid::v4(),
            'startedAt' => '2026-09-23T10:00:05+00:00',
        ]));
        self::assertResponseStatusCodeSame(201);
        $this->put($client, $path, $raw, $this->payload(['state' => 'preparing', 'at' => '2026-09-23T10:00:02+00:00']));

        self::assertResponseIsSuccessful();
        self::assertSame(WorkerRunState::Running, $this->onlyRun()->state);
    }

    public function test_the_work_request_of_the_first_report_is_stored(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-states-work@example.com');
        $project = $this->project($em, $owner, 'Run States Work');
        $raw = $this->agentToken($client, $owner);
        $workRequestId = (string) Uuid::v7();

        $this->put($client, $this->path($project->id, (string) Uuid::v4()), $raw, $this->payload(['workRequestId' => $workRequestId, 'workKind' => 'fix', 'ruleId' => 'fix-on-red']));

        self::assertResponseStatusCodeSame(201);
        $run = $this->onlyRun();
        self::assertSame($workRequestId, $run->workRequestId?->toRfc4122());
        self::assertSame('fix', $run->workKind);
        self::assertSame('fix-on-red', $run->ruleId);
    }

    /** A run of an old bridge rule names no work request. */
    public function test_a_report_with_no_work_fields_is_accepted(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-states-no-work@example.com');
        $project = $this->project($em, $owner, 'Run States No Work');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $this->path($project->id, (string) Uuid::v4()), $raw, $this->payload(['workKind' => null]));

        self::assertResponseStatusCodeSame(201);
        $run = $this->onlyRun();
        self::assertNull($run->workRequestId);
        self::assertNull($run->workKind);
        self::assertNull($run->ruleId);
    }

    /** A newer bridge can send a field this server does not know. */
    public function test_an_unknown_field_is_ignored(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-states-unknown-field@example.com');
        $project = $this->project($em, $owner, 'Run States Unknown Field');
        $raw = $this->agentToken($client, $owner);

        $this->put($client, $this->path($project->id, (string) Uuid::v4()), $raw, $this->payload([
            'futureField' => ['any' => 'shape'],
            'trigger' => ['eventType' => 'pull_request.fix_requested'],
        ]));

        self::assertResponseStatusCodeSame(201);
        self::assertSame('plan', $this->onlyRun()->workKind);
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
        yield 'a worker pool with a space and capitals' => [['workerPool' => 'Quick Pool']];
        yield 'a worker pool that starts with a digit' => [['workerPool' => '1st']];
        yield 'a worker pool above the limit' => [['workerPool' => 'a'.str_repeat('b', 40)]];
        yield 'a worker pool with a trailing newline' => [['workerPool' => "default\n"]];
        yield 'a blank worker pool' => [['workerPool' => '']];
        $experiment = ['experiment' => 'plan-model', 'variant' => 'opus'];
        yield 'an experiment with capitals and punctuation' => [['experiment' => 'Opus!', 'variant' => 'opus']];
        yield 'a blank experiment' => [['experiment' => '', 'variant' => 'opus']];
        yield 'an experiment above the limit' => [['experiment' => str_repeat('e', 65), 'variant' => 'opus']];
        yield 'a variant with capitals and punctuation' => [[...$experiment, 'variant' => 'Opus!']];
        yield 'a variant with a trailing newline' => [[...$experiment, 'variant' => "opus\n"]];
        yield 'a switched-from variant with capitals and punctuation' => [[...$experiment, 'switchedFrom' => 'Opus!']];
        yield 'a blank requested model' => [[...$experiment, 'requestedModel' => '']];
        yield 'a requested model above the limit' => [[...$experiment, 'requestedModel' => str_repeat('m', 101)]];
        yield 'a requested model with a newline' => [[...$experiment, 'requestedModel' => "claude\nopus"]];
        yield 'an experiment with no variant' => [['experiment' => 'plan-model']];
        yield 'a variant with no experiment' => [['variant' => 'opus']];
        yield 'a requested model with no experiment' => [['requestedModel' => 'claude-opus-4']];
        yield 'a switched-from variant with no experiment' => [['switchedFrom' => 'sonnet']];
        yield 'a timed-out state, which only the server infers' => [['state' => 'timed-out']];
        yield 'a lost state, which only the server infers' => [['state' => 'lost']];
        yield 'a closed state, which only an interactive run reaches' => [['state' => 'closed']];
        yield 'a stop that ends before it starts' => [['state' => 'stopped', 'startedAt' => '2026-09-23T10:00:00+00:00', 'endedAt' => '2026-09-23T09:00:00+00:00']];
        yield 'a stop with no end whose moment is before its start' => [['state' => 'stopped', 'startedAt' => '2026-09-23T10:00:05+00:00']];
        yield 'a missing moment' => [['at' => null]];
        yield 'a bridge id that is not a uuid' => [['bridgeId' => 'nope']];
        yield 'a card number of zero' => [['cardNumber' => 0]];
        yield 'a blank work kind' => [['workKind' => ' ']];
        yield 'a work kind with a colon' => [['workKind' => 'work:fix']];
        yield 'a work kind above the limit' => [['workKind' => 'a'.str_repeat('b', 40)]];
        yield 'a work request id that is not a uuid' => [['workRequestId' => 'nope']];
        yield 'a blank rule id' => [['ruleId' => '']];
        yield 'a rule id with a space' => [['ruleId' => 'not a rule']];
        yield 'running with no session' => [['state' => 'running', 'startedAt' => '2026-09-23T10:00:00+00:00']];
        yield 'a worker kind running with no session' => [['kind' => 'worker', 'state' => 'running', 'startedAt' => '2026-09-23T10:00:00+00:00']];
        yield 'an interactive kind, which a bridge never reports' => [['kind' => 'interactive']];
        yield 'an unknown kind' => [['kind' => 'script']];
        yield 'a command run with a result status' => [array_merge($outcome, ['kind' => 'command', 'state' => 'succeeded', 'hasResult' => true, 'resultStatus' => 'finished'])];
        yield 'running with no start' => [['state' => 'running', 'sessionId' => (string) Uuid::v4()]];
        yield 'an outcome with no end' => [array_merge($outcome, ['state' => 'succeeded', 'endedAt' => null])];
        yield 'an outcome with no output' => [array_merge($outcome, ['state' => 'succeeded', 'output' => null])];
        yield 'succeeded with a non-zero exit code' => [array_merge($outcome, ['state' => 'succeeded', 'exitCode' => 1])];
        yield 'failed with a zero exit code' => [array_merge($outcome, ['state' => 'failed'])];
        yield 'not-started with an exit code' => [array_merge($outcome, ['state' => 'not-started', 'failureReason' => 'no claude'])];
        yield 'not-started with no reason' => [array_merge($outcome, ['state' => 'not-started', 'exitCode' => null])];
        yield 'succeeded with no result, which is no-result' => [array_merge($outcome, ['state' => 'succeeded', 'hasResult' => false])];
        yield 'no-result with a result' => [array_merge($outcome, ['state' => 'no-result', 'hasResult' => true])];
        yield 'no-result with a non-zero exit code' => [array_merge($outcome, ['state' => 'no-result', 'exitCode' => 1, 'hasResult' => false])];
        yield 'a result flag with no exit code' => [array_merge($outcome, ['state' => 'not-started', 'exitCode' => null, 'failureReason' => 'no claude', 'hasResult' => false])];
        yield 'an end before the start' => [array_merge($outcome, ['state' => 'succeeded', 'endedAt' => '2026-09-23T09:00:00+00:00'])];
        $result = array_merge($outcome, ['hasResult' => true]);
        yield 'an unknown result status' => [array_merge($result, ['state' => 'succeeded', 'resultStatus' => 'done'])];
        yield 'a result status with no result flag' => [array_merge($outcome, ['state' => 'no-result', 'hasResult' => false, 'resultStatus' => 'blocked'])];
        yield 'a result reason with no result flag' => [array_merge($outcome, ['state' => 'no-result', 'hasResult' => false, 'resultReason' => 'stacked'])];
        yield 'succeeded with a blocked status' => [array_merge($result, ['state' => 'succeeded', 'resultStatus' => 'blocked'])];
        yield 'blocked with a non-zero exit code' => [array_merge($result, ['state' => 'blocked', 'exitCode' => 1, 'resultStatus' => 'blocked'])];
        yield 'unfinished with no result' => [array_merge($outcome, ['state' => 'unfinished', 'hasResult' => false])];
        yield 'gave-up after a finished worker' => [array_merge($result, ['state' => 'gave-up', 'resultStatus' => 'finished'])];
        yield 'gave-up after a blocked worker' => [array_merge($result, ['state' => 'gave-up', 'resultStatus' => 'blocked'])];
        yield 'succeeded with a waiting status' => [array_merge($result, ['state' => 'succeeded', 'resultStatus' => 'waiting'])];
        yield 'waiting-on-forge with a finished status' => [array_merge($result, ['state' => 'waiting-on-forge', 'resultStatus' => 'finished'])];
        yield 'waiting-on-forge with a non-zero exit code' => [array_merge($result, ['state' => 'waiting-on-forge', 'exitCode' => 1, 'resultStatus' => 'waiting'])];
        yield 'waiting-on-forge with no result' => [array_merge($outcome, ['state' => 'waiting-on-forge', 'hasResult' => false])];
        yield 'gave-up after a worker that waits on the forge' => [array_merge($result, ['state' => 'gave-up', 'resultStatus' => 'waiting'])];
        yield 'gave-up after a run that never started' => [array_merge($outcome, ['state' => 'gave-up', 'exitCode' => null, 'failureReason' => 'no claude'])];
        yield 'result fields above the limit' => [array_merge($result, ['state' => 'succeeded', 'resultStatus' => 'finished', 'resultFields' => ['note' => str_repeat('x', 4000)]])];
        yield 'result fields as a list' => [array_merge($result, ['state' => 'succeeded', 'resultStatus' => 'finished', 'resultFields' => ['a', 'b']])];
        yield 'a result reason above the limit' => [array_merge($result, ['state' => 'succeeded', 'resultReason' => 'a'.str_repeat('b', 40)])];
        yield 'a result reason with capitals' => [array_merge($result, ['state' => 'succeeded', 'resultReason' => 'Stacked'])];
        yield 'a result reason with a newline' => [array_merge($result, ['state' => 'succeeded', 'resultReason' => "stacked\n"])];
        yield 'a continued run that is not a uuid' => [['continues' => 'nope']];
        yield 'a skip reason above the limit' => [array_merge($outcome, ['state' => 'succeeded', 'resumeSkipped' => str_repeat('x', 51)])];
        yield 'a drop reason the bridge does not send' => [['state' => 'dropped', 'reason' => 'bored']];
        yield 'a replacement that is not a uuid' => [['state' => 'replaced', 'replacedBy' => 'nope']];
        yield 'a chain cap of zero' => [['state' => 'waiting-for-person', 'maxChain' => 0]];
        foreach (self::invalidUsage() as $name => $usage) {
            yield $name => [array_merge($result, ['state' => 'succeeded', 'usage' => $usage])];
        }
    }

    /** @return iterable<string, array<string, mixed>> */
    public static function invalidUsage(): iterable
    {
        $model = ['inputTokens' => 1, 'outputTokens' => 2, 'cacheReadTokens' => 3, 'cacheWriteTokens' => 4, 'costUsd' => 0.5];

        yield 'usage from an unknown source' => ['source' => 'guessed', 'models' => []];
        yield 'usage with no source' => ['models' => []];
        yield 'usage with no models' => ['source' => 'reported'];
        yield 'usage models as a list' => ['source' => 'reported', 'models' => [$model]];
        yield 'usage with a blank model name' => ['source' => 'reported', 'models' => ['' => $model]];
        yield 'usage with a model name above the limit' => ['source' => 'reported', 'models' => [str_repeat('m', 101) => $model]];
        yield 'usage with too many models' => ['source' => 'reported', 'models' => array_combine(
            array_map(static fn (int $i): string => 'model-'.$i, range(1, 21)),
            array_fill(0, 21, $model),
        )];
        yield 'usage with a negative token count' => ['source' => 'reported', 'models' => ['claude' => array_merge($model, ['outputTokens' => -1])]];
        yield 'usage with a missing token count' => ['source' => 'reported', 'models' => ['claude' => array_diff_key($model, ['cacheReadTokens' => true])]];
        yield 'usage with a token count as text' => ['source' => 'reported', 'models' => ['claude' => array_merge($model, ['inputTokens' => 'many'])]];
        yield 'usage with a negative cost' => ['source' => 'reported', 'models' => ['claude' => array_merge($model, ['costUsd' => -0.1])]];
        yield 'usage with a cost the column cannot hold' => ['source' => 'reported', 'models' => ['claude' => array_merge($model, ['costUsd' => 1000000])]];
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

    /** @return array<string, mixed> */
    private static function outcome(): array
    {
        return [
            'state' => 'succeeded',
            'sessionId' => (string) Uuid::v4(),
            'startedAt' => '2026-09-23T10:00:00+00:00',
            'endedAt' => '2026-09-23T10:01:00+00:00',
            'exitCode' => 0,
            'hasResult' => true,
            'failureReason' => null,
            'output' => '',
        ];
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
            'workKind' => 'plan',
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
