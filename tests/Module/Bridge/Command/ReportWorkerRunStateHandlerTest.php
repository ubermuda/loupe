<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ReportWorkerRunStateCommand;
use App\Module\Bridge\Command\ReportWorkerRunStateHandler;
use App\Module\Bridge\Command\ReportWorkerRunStateResult;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\Repository\WorkerRunStateChangeRepository;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunModelUsage;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunUsageReport;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\RecordingAuditor;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\AuditOutcome;

final class ReportWorkerRunStateHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    private const string BRIDGE = '0199a0e2-9d4c-7c5e-9f2a-3b1c6d7e8f90';

    private const string SESSION = '5f0c2b1e-8d4a-4c3b-9e2f-1a0b3c4d5e6f';

    /**
     * Each step is a state the bridge reports, or a server inference written
     * straight to the run. The expectation is the run's state at the end.
     *
     * @return iterable<string, array{list<string>, WorkerRunState}>
     */
    public static function sequences(): iterable
    {
        yield 'a run moves forward' => [['queued', 'running', 'succeeded'], WorkerRunState::Succeeded];
        yield 'queued and resumed arrive together' => [['queued', 'resumed'], WorkerRunState::Resumed];
        yield 'a late queued does not move a running run back' => [['running', 'queued'], WorkerRunState::Running];
        yield 'a late running does not reopen a closed run' => [['succeeded', 'running'], WorkerRunState::Succeeded];
        yield 'a closed state never replaces another' => [['queued', 'dropped', 'succeeded'], WorkerRunState::Dropped];
        yield 'an outcome replaces timed-out' => [['queued', 'running', 'server:timed-out', 'failed'], WorkerRunState::Failed];
        yield 'a newer open state replaces timed-out' => [['queued', 'server:timed-out', 'running'], WorkerRunState::Running];
        yield 'a stale open state leaves timed-out alone' => [['running', 'server:timed-out', 'queued'], WorkerRunState::TimedOut];
        yield 'an outcome replaces lost' => [['running', 'server:lost', 'succeeded'], WorkerRunState::Succeeded];
        yield 'an open state leaves lost alone' => [['queued', 'server:lost', 'running'], WorkerRunState::Lost];
        yield 'the first state may be closed' => [['waiting-for-person'], WorkerRunState::WaitingForPerson];
    }

    /**
     * @param list<string> $steps
     */
    #[DataProvider('sequences')]
    public function test_the_run_moves_forward_only(array $steps, WorkerRunState $expected): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-sequence-'.md5(serialize($steps)));
        $runKey = Uuid::v4();

        $run = null;
        foreach ($steps as $step) {
            if (str_starts_with($step, 'server:')) {
                self::assertInstanceOf(WorkerRun::class, $run);
                $this->infer($run, WorkerRunState::from(substr($step, 7)));

                continue;
            }
            $run = $this->report($owner, $project, $runKey, WorkerRunState::from($step))->run;
        }

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertSame($expected, $run->state);
        // Every distinct state leaves one row, whether it moved the run or not.
        $expectedRows = array_values(array_unique(array_map(static fn (string $step): string => str_replace('server:', '', $step), $steps)));
        $rows = array_map(static fn (WorkerRunStateChange $change): string => $change->state->value, $this->history($run));
        sort($expectedRows);
        sort($rows);
        self::assertSame($expectedRows, $rows);
    }

    public function test_an_unknown_run_is_created_from_the_report(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-create');
        $runKey = Uuid::v4();
        $cardId = Uuid::v7();

        $result = $this->report($owner, $project, $runKey, WorkerRunState::Queued, cardId: $cardId, cardNumber: 12, ruleName: 'review');

        self::assertTrue($result->newState);
        self::assertInstanceOf(WorkerRun::class, $result->run);
        self::assertSame($runKey->toRfc4122(), $result->run->runKey?->toRfc4122());
        self::assertSame(self::BRIDGE, $result->run->bridgeId?->toRfc4122());
        self::assertSame(WorkerRunKind::Worker, $result->run->kind);
        self::assertSame($cardId->toRfc4122(), $result->run->cardId->toRfc4122());
        self::assertSame(12, $result->run->cardNumber);
        self::assertSame('review', $result->run->ruleName);
        self::assertSame(WorkerRunState::Queued, $result->run->state);
    }

    public function test_the_same_run_key_in_another_project_is_another_run(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-two-projects');
        $second = $this->project($this->em(), $owner, 'Second Handler Project');
        $runKey = Uuid::v4();

        $first = $this->report($owner, $project, $runKey, WorkerRunState::Queued)->run;
        $other = $this->report($owner, $second, $runKey, WorkerRunState::Queued)->run;

        self::assertNotNull($first);
        self::assertNotNull($other);
        self::assertNotSame((string) $first->id, (string) $other->id);
    }

    public function test_a_retry_of_the_last_open_state_reopens_a_timed_out_run(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-retry-timed-out');
        $runKey = Uuid::v4();

        $this->report($owner, $project, $runKey, WorkerRunState::Queued);
        $run = $this->report($owner, $project, $runKey, WorkerRunState::Running)->run;
        self::assertInstanceOf(WorkerRun::class, $run);
        $this->infer($run, WorkerRunState::TimedOut);
        $result = $this->report($owner, $project, $runKey, WorkerRunState::Running);

        self::assertTrue($result->newState);
        self::assertSame(WorkerRunState::Running, $run->state);
        $history = $this->history($run);
        $rows = array_map(static fn (WorkerRunStateChange $change): string => $change->state->value, $history);
        sort($rows);
        self::assertSame(['queued', 'running', 'running', 'timed-out'], $rows);
        $timedOut = array_values(array_filter($history, static fn (WorkerRunStateChange $change): bool => WorkerRunState::TimedOut === $change->state));
        $latest = array_reduce($history, static fn (?WorkerRunStateChange $carry, WorkerRunStateChange $change): WorkerRunStateChange => null === $carry || $change->at >= $carry->at ? $change : $carry);
        self::assertSame(WorkerRunState::Running, $latest?->state);
        self::assertGreaterThanOrEqual($timedOut[0]->at, $latest->at);
    }

    public function test_a_new_outcome_after_timed_out_keeps_its_reported_time(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-outcome-time');
        $runKey = Uuid::v4();

        $run = $this->report($owner, $project, $runKey, WorkerRunState::Running)->run;
        self::assertInstanceOf(WorkerRun::class, $run);
        $this->infer($run, WorkerRunState::TimedOut);
        $this->report($owner, $project, $runKey, WorkerRunState::Failed);

        $failed = array_values(array_filter($this->history($run), static fn (WorkerRunStateChange $change): bool => WorkerRunState::Failed === $change->state));
        self::assertCount(1, $failed);
        self::assertSame('2026-09-23 10:0'.WorkerRunState::Failed->rank().':00', $failed[0]->at->format('Y-m-d H:i:s'));
    }

    /** The bridge sends a start with a spawn failure for the old report, and it never ran. */
    public function test_a_run_that_never_started_keeps_no_start_and_no_session(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-not-started');

        $run = $this->report($owner, $project, Uuid::v4(), WorkerRunState::NotStarted, failureReason: 'spawn failed')->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertSame(WorkerRunState::NotStarted, $run->state);
        self::assertNull($run->startedAt);
        self::assertNull($run->sessionId);
    }

    public function test_a_repeat_writes_nothing(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-repeat');
        $runKey = Uuid::v4();

        $this->report($owner, $project, $runKey, WorkerRunState::Queued);
        $result = $this->report($owner, $project, $runKey, WorkerRunState::Queued);

        self::assertFalse($result->newState);
        self::assertInstanceOf(WorkerRun::class, $result->run);
        self::assertCount(1, $this->history($result->run));
    }

    public function test_an_outcome_records_its_data(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-outcome');
        $runKey = Uuid::v4();

        $this->report($owner, $project, $runKey, WorkerRunState::Running);
        $run = $this->report($owner, $project, $runKey, WorkerRunState::NotStarted, failureReason: 'no claude')->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertSame(WorkerRunState::NotStarted, $run->state);
        self::assertNull($run->exitCode);
        self::assertSame('no claude', $run->failureReason);
        self::assertSame('2026-09-23 10:05:00', $run->endedAt?->format('Y-m-d H:i:s'));
        self::assertSame('output', $run->output);
    }

    public function test_a_no_result_outcome_records_the_result_flag(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-no-result');
        $runKey = Uuid::v4();

        $this->report($owner, $project, $runKey, WorkerRunState::Running);
        $run = $this->report($owner, $project, $runKey, WorkerRunState::NoResult)->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertSame(WorkerRunState::NoResult, $run->state);
        self::assertSame(0, $run->exitCode);
        self::assertFalse($run->hasResult);
    }

    /** The outcome arrived first, so the late running report still names the session. */
    public function test_a_late_running_report_fills_the_session_of_a_closed_run(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-late-running');
        $runKey = Uuid::v4();

        $this->report($owner, $project, $runKey, WorkerRunState::Succeeded, withStart: false);
        $run = $this->report($owner, $project, $runKey, WorkerRunState::Running)->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertSame(WorkerRunState::Succeeded, $run->state);
        self::assertSame(self::SESSION, $run->sessionId?->toRfc4122());
        self::assertSame('2026-09-23 10:00:00', $run->startedAt?->format('Y-m-d H:i:s'));
        self::assertSame(0, $run->exitCode);
    }

    /** A closed report that does not move the run leaves the outcome of the state it holds. */
    public function test_a_closed_report_that_does_not_move_the_run_keeps_its_outcome(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-closed-kept');
        $runKey = Uuid::v4();

        $this->report($owner, $project, $runKey, WorkerRunState::Dropped);
        $run = $this->report($owner, $project, $runKey, WorkerRunState::Failed)->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertSame(WorkerRunState::Dropped, $run->state);
        self::assertNull($run->exitCode);
        self::assertNull($run->endedAt);
        self::assertSame('', $run->output);
    }

    public function test_the_first_closed_state_is_audited_once(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        [$owner, $project] = $this->scenario('handler-audit');
        $runKey = Uuid::v4();

        $this->report($owner, $project, $runKey, WorkerRunState::Queued);
        $this->report($owner, $project, $runKey, WorkerRunState::Running);
        self::assertSame([], $audit->records('bridge.worker_run_recorded'));

        $run = $this->report($owner, $project, $runKey, WorkerRunState::Failed)->run;
        $this->report($owner, $project, $runKey, WorkerRunState::Failed);
        $this->report($owner, $project, $runKey, WorkerRunState::Succeeded);

        self::assertInstanceOf(WorkerRun::class, $run);
        $record = $audit->record('bridge.worker_run_recorded');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame([
            'projectId' => (string) $project->id,
            'bridgeId' => self::BRIDGE,
            'runKey' => $runKey->toRfc4122(),
            'sessionId' => self::SESSION,
            'cardNumber' => 1,
            'ruleName' => 'plan',
            'state' => 'failed',
            'exitCode' => 1,
            'hasResult' => false,
            'spawnFailed' => false,
            'resultStatus' => null,
            'resultFieldNames' => null,
            'continuesRunKey' => null,
            'resumeIndex' => null,
            'resumeCap' => null,
            'cardColumn' => null,
            'resumeSkipped' => null,
        ], $record->context);
        self::assertCount(1, $audit->records('bridge.worker_run_recorded'));
    }

    /** Timed-out is the server's guess, so the outcome that replaces it is the first real close. */
    public function test_an_outcome_after_timed_out_is_audited(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        [$owner, $project] = $this->scenario('handler-audit-timed-out');
        $runKey = Uuid::v4();

        $run = $this->report($owner, $project, $runKey, WorkerRunState::Running)->run;
        self::assertInstanceOf(WorkerRun::class, $run);
        $this->infer($run, WorkerRunState::TimedOut);
        $this->report($owner, $project, $runKey, WorkerRunState::NotStarted, failureReason: 'no claude');

        self::assertTrue($audit->record('bridge.worker_run_recorded')->context['spawnFailed']);
    }

    public function test_a_resume_links_to_the_run_it_continues_by_run_key(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-link');
        $firstKey = Uuid::v4();

        $first = $this->report($owner, $project, $firstKey, WorkerRunState::Queued)->run;
        $resume = $this->report($owner, $project, Uuid::v4(), WorkerRunState::Queued, continues: $firstKey, resumeIndex: 1, resumeCap: 2, cardColumn: 'implementation')->run;

        self::assertInstanceOf(WorkerRun::class, $first);
        self::assertInstanceOf(WorkerRun::class, $resume);
        self::assertSame((string) $first->id, (string) $resume->continuesRun?->id);
        self::assertSame(1, $resume->resumeIndex);
        self::assertSame(2, $resume->resumeCap);
        self::assertSame('implementation', $resume->cardColumn);
    }

    public function test_a_run_key_the_server_does_not_hold_leaves_no_link(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-link-unknown');

        $resume = $this->report($owner, $project, Uuid::v4(), WorkerRunState::Queued, continues: Uuid::v4(), resumeIndex: 1, resumeCap: 2)->run;

        self::assertInstanceOf(WorkerRun::class, $resume);
        self::assertNull($resume->continuesRun);
        self::assertSame(1, $resume->resumeIndex);
    }

    public function test_a_run_of_another_bridge_is_never_the_continued_run(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-link-other-bridge');
        $firstKey = Uuid::v4();

        $this->report($owner, $project, $firstKey, WorkerRunState::Queued, bridgeId: Uuid::v7());
        $resume = $this->report($owner, $project, Uuid::v4(), WorkerRunState::Queued, continues: $firstKey)->run;

        self::assertInstanceOf(WorkerRun::class, $resume);
        self::assertNull($resume->continuesRun);
    }

    public function test_a_later_report_does_not_change_the_link(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-link-later');
        $firstKey = Uuid::v4();
        $resumeKey = Uuid::v4();

        $this->report($owner, $project, $firstKey, WorkerRunState::Queued);
        $this->report($owner, $project, $resumeKey, WorkerRunState::Queued);
        $resume = $this->report($owner, $project, $resumeKey, WorkerRunState::Running, continues: $firstKey, resumeIndex: 1)->run;

        self::assertInstanceOf(WorkerRun::class, $resume);
        self::assertNull($resume->continuesRun);
        self::assertNull($resume->resumeIndex);
    }

    public function test_an_outcome_stores_the_structured_result(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        [$owner, $project] = $this->scenario('handler-structured');
        $firstKey = Uuid::v4();
        $resumeKey = Uuid::v4();

        $this->report($owner, $project, $firstKey, WorkerRunState::Queued);
        $this->report($owner, $project, $resumeKey, WorkerRunState::Queued, continues: $firstKey, resumeIndex: 2, resumeCap: 2, cardColumn: 'implementation');
        $run = $this->report($owner, $project, $resumeKey, WorkerRunState::GaveUp, resultStatus: 'unfinished', resultFields: ['pullRequest' => 'https://example.com/pull/1'], resumeSkipped: 'card_moved')->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertSame(WorkerRunState::GaveUp, $run->state);
        self::assertSame('unfinished', $run->resultStatus);
        self::assertSame(['pullRequest' => 'https://example.com/pull/1'], $run->resultFields);
        self::assertSame('card_moved', $run->resumeSkipped);
        $context = $audit->record('bridge.worker_run_recorded')->context;
        self::assertSame('unfinished', $context['resultStatus']);
        self::assertSame('pullRequest', $context['resultFieldNames']);
        self::assertSame($firstKey->toRfc4122(), $context['continuesRunKey']);
        self::assertSame(2, $context['resumeIndex']);
        self::assertSame(2, $context['resumeCap']);
        self::assertSame('implementation', $context['cardColumn']);
        self::assertSame('card_moved', $context['resumeSkipped']);
    }

    public function test_a_project_the_owner_does_not_hold_yields_no_run(): void
    {
        self::bootKernel();
        [, $project] = $this->scenario('handler-foreign');
        $stranger = $this->user($this->em(), 'handler-stranger@example.com');

        $result = $this->report($stranger, $project, Uuid::v4(), WorkerRunState::Queued);

        self::assertNull($result->run);
        self::assertFalse($result->newState);
    }

    public function test_the_outcome_that_closes_the_run_writes_its_usage(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-usage-first');
        $runKey = Uuid::v4();

        $this->report($owner, $project, $runKey, WorkerRunState::Running);
        $run = $this->report($owner, $project, $runKey, WorkerRunState::Succeeded, usage: self::usage(WorkerRunUsageSource::Reported, 'claude-opus', 10))->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertSame(WorkerRunUsageSource::Reported, $run->usageSource);
        self::assertSame([['claude-opus', 10]], $this->usageOf($run));
    }

    public function test_a_repeat_outcome_writes_no_usage(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-usage-repeat');
        $runKey = Uuid::v4();

        $this->report($owner, $project, $runKey, WorkerRunState::Succeeded, usage: self::usage(WorkerRunUsageSource::Estimated, 'claude-opus', 10));
        $run = $this->report($owner, $project, $runKey, WorkerRunState::Succeeded, usage: self::usage(WorkerRunUsageSource::Reported, 'claude-sonnet', 20))->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertSame(WorkerRunUsageSource::Estimated, $run->usageSource);
        self::assertSame([['claude-opus', 10]], $this->usageOf($run));
    }

    /** The run already holds another closed state, so this outcome writes nothing of its own. */
    public function test_an_outcome_that_does_not_move_the_run_writes_no_usage(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-usage-kept');
        $runKey = Uuid::v4();

        $this->report($owner, $project, $runKey, WorkerRunState::Dropped);
        $run = $this->report($owner, $project, $runKey, WorkerRunState::Failed, usage: self::usage(WorkerRunUsageSource::Reported, 'claude-opus', 10))->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertNull($run->usageSource);
        self::assertSame([], $this->usageOf($run));
    }

    public function test_an_outcome_with_no_usage_leaves_the_usage_unknown(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-usage-absent');

        $run = $this->report($owner, $project, Uuid::v4(), WorkerRunState::Succeeded)->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertNull($run->usageSource);
        self::assertSame([], $this->usageOf($run));
    }

    public function test_usage_with_no_models_records_a_run_that_spent_nothing(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-usage-zero');

        $run = $this->report($owner, $project, Uuid::v4(), WorkerRunState::NotStarted, usage: new WorkerRunUsageReport(WorkerRunUsageSource::Reported, []))->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertSame(WorkerRunUsageSource::Reported, $run->usageSource);
        self::assertSame([], $this->usageOf($run));
    }

    public function test_an_open_state_ignores_its_usage(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-usage-open');

        $run = $this->report($owner, $project, Uuid::v4(), WorkerRunState::Running, usage: self::usage(WorkerRunUsageSource::Reported, 'claude-opus', 10))->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertNull($run->usageSource);
        self::assertSame([], $this->usageOf($run));
    }

    /** @return array{User, Project} */
    private function scenario(string $name): array
    {
        $em = $this->em();
        $owner = $this->user($em, $name.'@example.com');

        return [$owner, $this->project($em, $owner, 'Project '.substr(md5($name), 0, 8))];
    }

    /** @param array<string, mixed>|null $resultFields */
    private function report(
        User $owner,
        Project $project,
        Uuid $runKey,
        WorkerRunState $state,
        ?Uuid $cardId = null,
        int $cardNumber = 1,
        string $ruleName = 'plan',
        ?string $failureReason = null,
        bool $withStart = true,
        ?Uuid $continues = null,
        ?int $resumeIndex = null,
        ?int $resumeCap = null,
        ?string $cardColumn = null,
        ?string $resultStatus = null,
        ?array $resultFields = null,
        ?string $resumeSkipped = null,
        ?Uuid $bridgeId = null,
        ?WorkerRunUsageReport $usage = null,
    ): ReportWorkerRunStateResult {
        $outcome = $state->isOutcome();
        $started = $withStart && ($outcome || WorkerRunState::Running === $state);

        $handler = self::getContainer()->get(ReportWorkerRunStateHandler::class);
        self::assertInstanceOf(ReportWorkerRunStateHandler::class, $handler);

        return $handler(new ReportWorkerRunStateCommand(
            owner: $owner,
            handle: (string) $project->id,
            runKey: $runKey,
            bridgeId: $bridgeId ?? Uuid::fromString(self::BRIDGE),
            state: $state,
            at: new \DateTimeImmutable('2026-09-23 10:0'.$state->rank().':00'),
            cardId: $cardId ?? Uuid::fromString('0199a0e2-b1f3-7a44-9c11-2d3e4f506172'),
            cardNumber: $cardNumber,
            ruleName: $ruleName,
            sessionId: $started ? Uuid::fromString(self::SESSION) : null,
            startedAt: $started ? new \DateTimeImmutable('2026-09-23 10:00:00') : null,
            endedAt: $outcome ? new \DateTimeImmutable('2026-09-23 10:05:00') : null,
            exitCode: match ($state) {
                WorkerRunState::Succeeded, WorkerRunState::NoResult, WorkerRunState::GaveUp => 0,
                WorkerRunState::Failed => 1,
                default => null,
            },
            hasResult: match ($state) {
                WorkerRunState::Succeeded, WorkerRunState::GaveUp => true,
                WorkerRunState::Failed, WorkerRunState::NoResult => false,
                default => null,
            },
            failureReason: WorkerRunState::NotStarted === $state ? ($failureReason ?? 'no claude') : null,
            output: $outcome ? 'output' : null,
            resultStatus: $resultStatus,
            resultFields: $resultFields,
            continues: $continues,
            resumeIndex: $resumeIndex,
            resumeCap: $resumeCap,
            cardColumn: $cardColumn,
            resumeSkipped: $resumeSkipped,
            usage: $usage,
        ));
    }

    private static function usage(WorkerRunUsageSource $source, string $model, int $inputTokens): WorkerRunUsageReport
    {
        return new WorkerRunUsageReport($source, [new WorkerRunModelUsage($model, $inputTokens, 1, 2, 3, '0.500000')]);
    }

    /** @return list<array{string, int}> */
    private function usageOf(WorkerRun $run): array
    {
        /** @var list<array{model: string, input_tokens: int}> $rows */
        $rows = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT model, input_tokens FROM bridge_worker_run_usage WHERE run_id = ? ORDER BY model',
            [(string) $run->id],
        );

        return array_map(static fn (array $row): array => [$row['model'], $row['input_tokens']], $rows);
    }

    /** What the timeout sweep and the run inventory write. */
    private function infer(WorkerRun $run, WorkerRunState $state): void
    {
        $em = $this->em();
        $run->moveTo($state);
        $em->persist(new WorkerRunStateChange($run, $state, new \DateTimeImmutable('2026-09-23 10:04:00')));
        $em->flush();
    }

    /** @return list<WorkerRunStateChange> */
    private function history(WorkerRun $run): array
    {
        $repository = self::getContainer()->get(WorkerRunStateChangeRepository::class);
        self::assertInstanceOf(WorkerRunStateChangeRepository::class, $repository);

        return $repository->findForRun($run);
    }
}
