<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\LiveUpdates;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ReportWorkerRunStateCommand;
use App\Module\Bridge\Command\ReportWorkerRunStateHandler;
use App\Module\Bridge\Command\ReportWorkerRunStateResult;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\Event\WorkerRunQueued;
use App\Module\Bridge\Repository\WorkerRunStateChangeRepository;
use App\Module\Bridge\Service\WorkerRunChangedPublisher;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunModelUsage;
use App\Module\Bridge\ValueObject\WorkerRunReason;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunUsageReport;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\DispatchedEvents;
use App\Tests\Support\RecordingAuditor;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\FeatureFlagsBundle\Reader\FeatureFlagReaderInterface;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class ReportWorkerRunStateHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_a_new_state_announces_the_card_after_the_commit(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-announce');
        $cardId = Uuid::v7();
        $changes = DispatchedEvents::of(self::getContainer(), WorkerRunChanged::class);
        $depth = $this->em()->getConnection()->getTransactionNestingLevel();

        $this->report($owner, $project, Uuid::v4(), WorkerRunState::Queued, cardId: $cardId);

        self::assertCount(1, $changes->events());
        self::assertEquals($project->id, $changes->events()[0]->projectId);
        self::assertSame([$cardId->toRfc4122()], $changes->events()[0]->cardIds);
        self::assertSame([$depth], $changes->transactionDepths());
    }

    public function test_a_repeat_or_a_foreign_project_announces_nothing(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-announce-none');
        $stranger = $this->user($this->em(), 'handler-announce-stranger@example.com');
        $runKey = Uuid::v4();
        $this->report($owner, $project, $runKey, WorkerRunState::Queued);
        $changes = DispatchedEvents::of(self::getContainer(), WorkerRunChanged::class);

        $repeat = $this->report($owner, $project, $runKey, WorkerRunState::Queued);
        $foreign = $this->report($stranger, $project, Uuid::v4(), WorkerRunState::Queued);

        self::assertFalse($repeat->newState);
        self::assertNull($foreign->run);
        self::assertSame([], $changes->events());
    }

    private const string BRIDGE = '0199a0e2-9d4c-7c5e-9f2a-3b1c6d7e8f90';

    private const string SESSION = '5f0c2b1e-8d4a-4c3b-9e2f-1a0b3c4d5e6f';

    private const array EXPERIMENT = ['experiment' => 'plan-model', 'variant' => 'opus', 'requestedModel' => 'claude-opus-4', 'switchedFrom' => 'sonnet'];

    /** @var list<Update> */
    private array $runsChanged = [];

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
        yield 'a person stops a running run' => [['running', 'stopping', 'stopped'], WorkerRunState::Stopped];
        yield 'a person stops a queued run' => [['queued', 'stopped'], WorkerRunState::Stopped];
        yield 'a late running does not move a stopping run back' => [['running', 'stopping', 'running'], WorkerRunState::Stopping];
        yield 'an outcome replaces stopping' => [['running', 'stopping', 'failed'], WorkerRunState::Failed];
        yield 'an outcome never replaces stopped' => [['running', 'stopped', 'failed'], WorkerRunState::Stopped];
        yield 'a stopping run reopens from timed-out' => [['running', 'server:timed-out', 'stopping'], WorkerRunState::Stopping];
        yield 'a stop replaces timed-out' => [['running', 'server:timed-out', 'stopped'], WorkerRunState::Stopped];
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

        $result = $this->report($owner, $project, $runKey, WorkerRunState::Queued, cardId: $cardId, cardNumber: 12, workKind: 'review');

        self::assertTrue($result->newState);
        self::assertInstanceOf(WorkerRun::class, $result->run);
        self::assertSame($runKey->toRfc4122(), $result->run->runKey?->toRfc4122());
        self::assertSame(self::BRIDGE, $result->run->bridgeId?->toRfc4122());
        self::assertSame(WorkerRunKind::Worker, $result->run->kind);
        self::assertSame($cardId->toRfc4122(), $result->run->subjectId->toRfc4122());
        self::assertSame(12, $result->run->cardNumber);
        self::assertSame('review', $result->run->workKind);
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
            'subjectType' => 'card',
            'subjectId' => '0199a0e2-b1f3-7a44-9c11-2d3e4f506172',
            'cardNumber' => 1,
            'workRequestId' => null,
            'workKind' => 'plan',
            'ruleId' => null,
            'state' => 'failed',
            'exitCode' => 1,
            'hasResult' => false,
            'spawnFailed' => false,
            'resultStatus' => null,
            'resultReason' => null,
            'resultFieldNames' => null,
            'continuesRunKey' => null,
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
        $resume = $this->report($owner, $project, Uuid::v4(), WorkerRunState::Queued, continues: $firstKey)->run;

        self::assertInstanceOf(WorkerRun::class, $first);
        self::assertInstanceOf(WorkerRun::class, $resume);
        self::assertSame((string) $first->id, (string) $resume->continuesRun?->id);
    }

    public function test_a_run_key_the_server_does_not_hold_leaves_no_link(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-link-unknown');

        $resume = $this->report($owner, $project, Uuid::v4(), WorkerRunState::Queued, continues: Uuid::v4())->run;

        self::assertInstanceOf(WorkerRun::class, $resume);
        self::assertNull($resume->continuesRun);
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
        $resume = $this->report($owner, $project, $resumeKey, WorkerRunState::Running, continues: $firstKey)->run;

        self::assertInstanceOf(WorkerRun::class, $resume);
        self::assertNull($resume->continuesRun);
    }

    public function test_an_outcome_stores_the_structured_result(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        [$owner, $project] = $this->scenario('handler-structured');
        $firstKey = Uuid::v4();
        $resumeKey = Uuid::v4();

        $this->report($owner, $project, $firstKey, WorkerRunState::Queued);
        $this->report($owner, $project, $resumeKey, WorkerRunState::Queued, continues: $firstKey);
        $run = $this->report($owner, $project, $resumeKey, WorkerRunState::GaveUp, resultStatus: 'unfinished', resultFields: ['pullRequest' => 'https://example.com/pull/1'], resumeSkipped: 'card_moved', resultReason: WorkerRunReason::WaitingChecks)->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertSame(WorkerRunState::GaveUp, $run->state);
        self::assertSame('unfinished', $run->resultStatus);
        self::assertSame(['pullRequest' => 'https://example.com/pull/1'], $run->resultFields);
        self::assertSame('card_moved', $run->resumeSkipped);
        self::assertSame(WorkerRunReason::WaitingChecks, $run->resultReason);
        $context = $audit->record('bridge.worker_run_recorded')->context;
        self::assertSame('unfinished', $context['resultStatus']);
        self::assertSame('waiting-checks', $context['resultReason']);
        self::assertSame('pullRequest', $context['resultFieldNames']);
        self::assertSame($firstKey->toRfc4122(), $context['continuesRunKey']);
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

    /** @return iterable<string, array{WorkerRunState, int}> */
    public static function closingPeaks(): iterable
    {
        yield 'an outcome' => [WorkerRunState::Succeeded, 123_456];
        yield 'a stop' => [WorkerRunState::Stopped, 98_765];
        yield 'an outcome with an empty context' => [WorkerRunState::Failed, 0];
    }

    #[DataProvider('closingPeaks')]
    public function test_the_report_that_closes_the_run_stores_its_peak_context(WorkerRunState $state, int $peak): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-peak-'.$state->value.'-'.$peak);
        $runKey = Uuid::v4();
        $this->report($owner, $project, $runKey, WorkerRunState::Running);

        $run = $this->report($owner, $project, $runKey, $state, peakContextTokens: $peak)->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertSame($state, $run->state);
        self::assertSame($peak, $this->storedPeak($run, 'bridge_worker_runs', 'id'));
        self::assertSame($peak, $this->storedPeak($run, 'bridge_worker_run_facts', 'run_id'));
    }

    public function test_a_closing_report_with_no_peak_keeps_the_peak_the_run_holds(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-peak-kept');
        $runKey = Uuid::v4();
        $running = $this->report($owner, $project, $runKey, WorkerRunState::Running)->run;
        self::assertInstanceOf(WorkerRun::class, $running);
        $running->peakContextTokens = 500;
        $this->em()->flush();

        $run = $this->report($owner, $project, $runKey, WorkerRunState::Succeeded)->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertSame(WorkerRunState::Succeeded, $run->state);
        self::assertSame(500, $this->storedPeak($run, 'bridge_worker_runs', 'id'));
    }

    public function test_an_outcome_with_no_peak_leaves_the_peak_unknown(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-peak-absent');

        $run = $this->report($owner, $project, Uuid::v4(), WorkerRunState::Succeeded)->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertNull($this->storedPeak($run, 'bridge_worker_runs', 'id'));
        self::assertNull($this->storedPeak($run, 'bridge_worker_run_facts', 'run_id'));
    }

    public function test_an_open_state_or_an_outcome_that_does_not_move_the_run_ignores_its_peak(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-peak-ignored');
        $open = $this->report($owner, $project, Uuid::v4(), WorkerRunState::Running, peakContextTokens: 10)->run;
        $runKey = Uuid::v4();
        $this->report($owner, $project, $runKey, WorkerRunState::Dropped);

        $late = $this->report($owner, $project, $runKey, WorkerRunState::Failed, peakContextTokens: 20)->run;

        self::assertInstanceOf(WorkerRun::class, $open);
        self::assertInstanceOf(WorkerRun::class, $late);
        self::assertSame(WorkerRunState::Dropped, $late->state);
        self::assertNull($this->storedPeak($open, 'bridge_worker_runs', 'id'));
        self::assertNull($this->storedPeak($late, 'bridge_worker_runs', 'id'));
    }

    public function test_a_stop_records_its_end_its_output_and_its_usage(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        [$owner, $project] = $this->scenario('handler-stopped');
        $runKey = Uuid::v4();

        $this->report($owner, $project, $runKey, WorkerRunState::Running);
        $this->report($owner, $project, $runKey, WorkerRunState::Stopping);
        self::assertSame([], $audit->records('bridge.worker_run_recorded'));
        $run = $this->report(
            $owner,
            $project,
            $runKey,
            WorkerRunState::Stopped,
            usage: self::usage(WorkerRunUsageSource::Reported, 'claude-opus', 10),
            endedAt: new \DateTimeImmutable('2026-09-23 10:06:00'),
            output: 'stopped halfway',
        )->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertSame(WorkerRunState::Stopped, $run->state);
        self::assertSame('2026-09-23 10:06:00', $run->endedAt?->format('Y-m-d H:i:s'));
        self::assertSame('stopped halfway', $run->output);
        self::assertNull($run->exitCode);
        self::assertNull($run->hasResult);
        self::assertSame('2026-09-23 10:00:00', $run->startedAt?->format('Y-m-d H:i:s'));
        self::assertSame([['claude-opus', 10]], $this->usageOf($run));
        self::assertSame('stopped', $audit->record('bridge.worker_run_recorded')->context['state']);
    }

    /** A queued run never started, so its stop carries no start, and the report time stands in for the end. */
    public function test_a_stop_with_no_end_ends_at_the_report_time(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-stopped-queued');
        $runKey = Uuid::v4();

        $this->report($owner, $project, $runKey, WorkerRunState::Queued);
        $run = $this->report($owner, $project, $runKey, WorkerRunState::Stopped)->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertSame(WorkerRunState::Stopped, $run->state);
        self::assertNull($run->startedAt);
        self::assertNull($run->sessionId);
        self::assertSame('2026-09-23 10:0'.WorkerRunState::Stopped->rank().':00', $run->endedAt?->format('Y-m-d H:i:s'));
        self::assertSame('', $run->output);
        self::assertNull($run->usageSource);
    }

    public function test_stopping_moves_the_run_and_nothing_else(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-stopping');
        $runKey = Uuid::v4();

        $this->report($owner, $project, $runKey, WorkerRunState::Running);
        $run = $this->report($owner, $project, $runKey, WorkerRunState::Stopping, usage: self::usage(WorkerRunUsageSource::Reported, 'claude-opus', 10), output: 'partial')->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertSame(WorkerRunState::Stopping, $run->state);
        self::assertNull($run->endedAt);
        self::assertSame('', $run->output);
        self::assertSame([], $this->usageOf($run));
    }

    public function test_the_first_report_stores_the_worker_pool(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-pool-first');

        $run = $this->report($owner, $project, Uuid::v4(), WorkerRunState::Queued, workerPool: 'default')->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertSame('default', $this->storedPool($run));
    }

    /** The bridge can move a queued run to another pool, and a repeat still carries the new one. */
    public function test_a_later_report_replaces_the_worker_pool(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-pool-replace');
        $runKey = Uuid::v4();

        $this->report($owner, $project, $runKey, WorkerRunState::Queued, workerPool: 'default');
        $repeat = $this->report($owner, $project, $runKey, WorkerRunState::Queued, workerPool: 'quick');
        self::assertFalse($repeat->newState);
        self::assertInstanceOf(WorkerRun::class, $repeat->run);
        self::assertSame('quick', $this->storedPool($repeat->run));

        $run = $this->report($owner, $project, $runKey, WorkerRunState::Running, workerPool: 'review')->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertSame('review', $this->storedPool($run));
    }

    public function test_a_report_without_a_worker_pool_keeps_the_stored_one(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-pool-keep');
        $runKey = Uuid::v4();

        $this->report($owner, $project, $runKey, WorkerRunState::Queued, workerPool: 'quick');
        $run = $this->report($owner, $project, $runKey, WorkerRunState::Running)->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertSame('quick', $this->storedPool($run));
    }

    /** An open Runs page shows the pool, so a repeat that moves the run to another pool must reload it. */
    public function test_a_repeat_that_moves_the_pool_tells_the_runs_page(): void
    {
        self::bootKernel();
        $this->recordRunsChanged();
        [$owner, $project] = $this->scenario('handler-pool-publish');
        $runKey = Uuid::v4();

        $this->report($owner, $project, $runKey, WorkerRunState::Queued, workerPool: 'default');
        self::assertCount(1, $this->publishedRunsChanged());

        $repeat = $this->report($owner, $project, $runKey, WorkerRunState::Queued, workerPool: 'quick');

        self::assertFalse($repeat->newState);
        self::assertCount(1, $this->publishedRunsChanged());
    }

    public function test_a_repeat_that_keeps_the_pool_tells_nobody(): void
    {
        self::bootKernel();
        $this->recordRunsChanged();
        [$owner, $project] = $this->scenario('handler-pool-quiet');
        $runKey = Uuid::v4();

        $this->report($owner, $project, $runKey, WorkerRunState::Queued, workerPool: 'default');
        // The guard: the first report publishes, so the recording works.
        self::assertCount(1, $this->publishedRunsChanged());

        $this->report($owner, $project, $runKey, WorkerRunState::Queued, workerPool: 'default');
        $this->report($owner, $project, $runKey, WorkerRunState::Queued);

        self::assertCount(0, $this->publishedRunsChanged());
    }

    /** An open Runs page shows the harness fields, so a repeat that fills one must reload it. */
    public function test_a_repeat_that_names_the_harness_tells_the_runs_page(): void
    {
        self::bootKernel();
        $this->recordRunsChanged();
        [$owner, $project] = $this->scenario('handler-harness-publish');
        $runKey = Uuid::v4();

        $this->report($owner, $project, $runKey, WorkerRunState::Queued, harness: 'codex');
        self::assertCount(1, $this->publishedRunsChanged());

        $this->report($owner, $project, $runKey, WorkerRunState::Queued, harness: 'codex');
        self::assertCount(0, $this->publishedRunsChanged());

        $repeat = $this->report($owner, $project, $runKey, WorkerRunState::Queued, harnessSessionId: 'thread-1');

        self::assertFalse($repeat->newState);
        self::assertCount(1, $this->publishedRunsChanged());
    }

    /** Codex names its thread only after it starts, and the model can come with the outcome. */
    public function test_a_later_report_fills_the_harness_fields_and_a_null_keeps_them(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-harness');
        $runKey = Uuid::v4();

        $this->report($owner, $project, $runKey, WorkerRunState::Queued, harness: 'codex', account: 'work');
        $repeat = $this->report($owner, $project, $runKey, WorkerRunState::Queued, harnessSessionId: 'thread-1');
        self::assertFalse($repeat->newState);
        $this->report($owner, $project, $runKey, WorkerRunState::Running);
        $run = $this->report($owner, $project, $runKey, WorkerRunState::Succeeded, model: 'gpt-5')->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        $this->em()->clear();
        $stored = $this->em()->find(WorkerRun::class, $run->id);
        self::assertInstanceOf(WorkerRun::class, $stored);
        self::assertSame(['codex', 'work', 'gpt-5', 'thread-1'], [$stored->harness, $stored->account, $stored->model, $stored->harnessSessionId]);
    }

    public function test_a_run_that_never_started_stores_its_harness(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-harness-not-started');

        $run = $this->report($owner, $project, Uuid::v4(), WorkerRunState::NotStarted, harness: 'codex', account: 'work')->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertSame(['codex', 'work'], [$run->harness, $run->account]);
    }

    public function test_the_running_report_stores_the_experiment(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-experiment-running');

        $run = $this->report($owner, $project, Uuid::v4(), WorkerRunState::Running, experiment: self::EXPERIMENT)->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertSame(self::EXPERIMENT, $this->storedExperiment($run));
    }

    /** Reports can arrive out of order, so the first one that names the experiment wins. */
    public function test_a_later_report_does_not_replace_the_experiment(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-experiment-kept');
        $runKey = Uuid::v4();
        $other = ['experiment' => 'other', 'variant' => 'haiku', 'requestedModel' => 'claude-haiku', 'switchedFrom' => 'opus'];

        $this->report($owner, $project, $runKey, WorkerRunState::Queued, experiment: self::EXPERIMENT);
        $this->report($owner, $project, $runKey, WorkerRunState::Queued, experiment: $other);
        $this->report($owner, $project, $runKey, WorkerRunState::Running, experiment: $other);
        $run = $this->report($owner, $project, $runKey, WorkerRunState::Succeeded, experiment: $other)->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertSame(WorkerRunState::Succeeded, $run->state);
        self::assertSame(self::EXPERIMENT, $this->storedExperiment($run));
    }

    /** A later report cannot fill one field of the experiment the run already holds. */
    public function test_a_later_report_does_not_add_to_the_experiment(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-experiment-grouped');
        $runKey = Uuid::v4();
        $first = [...self::EXPERIMENT, 'switchedFrom' => null];

        $this->report($owner, $project, $runKey, WorkerRunState::Running, experiment: $first);
        $run = $this->report($owner, $project, $runKey, WorkerRunState::Succeeded, experiment: [...self::EXPERIMENT, 'variant' => 'haiku'])->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertSame($first, $this->storedExperiment($run));
    }

    /** @return iterable<string, array{WorkerRunState}> */
    public static function outcomes(): iterable
    {
        yield 'succeeded' => [WorkerRunState::Succeeded];
        yield 'not started' => [WorkerRunState::NotStarted];
    }

    /** The running report may be lost, and a spawn failure follows the pin the bridge already resolved. */
    #[DataProvider('outcomes')]
    public function test_an_outcome_fills_the_experiment_no_earlier_report_carried(WorkerRunState $outcome): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-experiment-outcome-'.$outcome->value);
        $runKey = Uuid::v4();

        $this->report($owner, $project, $runKey, WorkerRunState::Queued);
        $run = $this->report($owner, $project, $runKey, $outcome, experiment: self::EXPERIMENT)->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertSame($outcome, $run->state);
        self::assertSame(self::EXPERIMENT, $this->storedExperiment($run));
    }

    /** An outcome that does not move the run still names the experiment the run used. */
    public function test_an_outcome_that_does_not_move_the_run_fills_the_experiment(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-experiment-late');
        $runKey = Uuid::v4();

        $this->report($owner, $project, $runKey, WorkerRunState::Dropped);
        $run = $this->report($owner, $project, $runKey, WorkerRunState::Failed, experiment: self::EXPERIMENT)->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertSame(WorkerRunState::Dropped, $run->state);
        self::assertSame(self::EXPERIMENT, $this->storedExperiment($run));
    }

    public function test_a_run_with_no_experiment_keeps_four_nulls(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-experiment-none');
        $runKey = Uuid::v4();

        $this->report($owner, $project, $runKey, WorkerRunState::Running);
        $run = $this->report($owner, $project, $runKey, WorkerRunState::Succeeded)->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertSame(['experiment' => null, 'variant' => null, 'requestedModel' => null, 'switchedFrom' => null], $this->storedExperiment($run));
    }

    public function test_the_report_that_creates_the_run_stores_its_work_request(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-work-store');
        $workRequestId = Uuid::v7();

        $run = $this->report($owner, $project, Uuid::v4(), WorkerRunState::Queued, workKind: 'implement', workRequestId: $workRequestId, ruleId: 'implement-on-entry')->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        $this->em()->clear();
        $stored = $this->em()->find(WorkerRun::class, $run->id);
        self::assertInstanceOf(WorkerRun::class, $stored);
        self::assertSame($workRequestId->toRfc4122(), $stored->workRequestId?->toRfc4122());
        self::assertSame('implement', $stored->workKind);
        self::assertSame('implement-on-entry', $stored->ruleId);
    }

    public function test_a_later_report_does_not_change_the_work_request(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-work-keep');
        $runKey = Uuid::v4();
        $workRequestId = Uuid::v7();
        $this->report($owner, $project, $runKey, WorkerRunState::Queued, workKind: 'implement', workRequestId: $workRequestId, ruleId: 'implement-on-entry');

        $run = $this->report($owner, $project, $runKey, WorkerRunState::Running, workKind: 'fix', workRequestId: Uuid::v7(), ruleId: 'fix-on-red')->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertSame($workRequestId->toRfc4122(), $run->workRequestId?->toRfc4122());
        self::assertSame('implement', $run->workKind);
        self::assertSame('implement-on-entry', $run->ruleId);
    }

    public function test_a_run_of_an_old_bridge_rule_stores_no_work_request(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-work-none');

        $run = $this->report($owner, $project, Uuid::v4(), WorkerRunState::Queued, workKind: null)->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertNull($run->workRequestId);
        self::assertNull($run->workKind);
        self::assertNull($run->ruleId);
    }

    public function test_a_new_queued_fix_run_is_announced_after_the_commit(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-queued-fix');
        $cardId = Uuid::v7();
        $queued = DispatchedEvents::of(self::getContainer(), WorkerRunQueued::class);
        $depth = $this->em()->getConnection()->getTransactionNestingLevel();

        $run = $this->report($owner, $project, Uuid::v4(), WorkerRunState::Queued, cardId: $cardId, workKind: 'fix')->run;

        self::assertInstanceOf(WorkerRun::class, $run);
        self::assertCount(1, $queued->events());
        $event = $queued->events()[0];
        self::assertEquals($project->id, $event->projectId);
        self::assertEquals($run->id, $event->runId);
        self::assertSame($cardId->toRfc4122(), $event->cardId->toRfc4122());
        self::assertSame([$depth], $queued->transactionDepths());
        self::assertNull($event->pullRequestNumber);
        self::assertNull($event->pullRequestUrl);
    }

    public function test_a_queued_fix_run_names_the_pull_request_of_its_work_request(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-queued-fix-request');
        $cardId = Uuid::v7();
        $request = $this->seedWorkRequest($this->em(), $project, $cardId, kind: 'fix', ruleId: 'fix-on-red');
        $request->context = new WorkRequestContext(pullRequestNumber: 41, pullRequestUrl: 'https://github.com/acme/widgets/pull/41');
        $this->em()->flush();
        $queued = DispatchedEvents::of(self::getContainer(), WorkerRunQueued::class);

        $this->report($owner, $project, Uuid::v4(), WorkerRunState::Queued, cardId: $cardId, workKind: 'fix', workRequestId: $request->id, ruleId: 'fix-on-red');

        self::assertCount(1, $queued->events());
        self::assertSame(41, $queued->events()[0]->pullRequestNumber);
        self::assertSame('https://github.com/acme/widgets/pull/41', $queued->events()[0]->pullRequestUrl);
    }

    public function test_a_queued_fix_run_that_names_the_request_of_another_card_names_no_pull_request(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-queued-fix-other');
        $request = $this->seedWorkRequest($this->em(), $project, Uuid::v7(), kind: 'fix', ruleId: 'fix-on-red');
        $request->context = new WorkRequestContext(pullRequestNumber: 41);
        $this->em()->flush();
        $queued = DispatchedEvents::of(self::getContainer(), WorkerRunQueued::class);

        $this->report($owner, $project, Uuid::v4(), WorkerRunState::Queued, cardId: Uuid::v7(), workKind: 'fix', workRequestId: $request->id, ruleId: 'fix-on-red');

        self::assertCount(1, $queued->events());
        self::assertNull($queued->events()[0]->pullRequestNumber);
    }

    public function test_a_repeat_of_the_queued_fix_report_announces_nothing_more(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-queued-fix-repeat');
        $runKey = Uuid::v4();
        $queued = DispatchedEvents::of(self::getContainer(), WorkerRunQueued::class);
        $this->report($owner, $project, $runKey, WorkerRunState::Queued, workKind: 'fix');
        // The guard: the first report announces, so the recording works.
        self::assertCount(1, $queued->events());

        $repeat = $this->report($owner, $project, $runKey, WorkerRunState::Queued, workKind: 'fix');

        self::assertFalse($repeat->newState);
        self::assertCount(1, $queued->events());
    }

    public function test_a_new_run_of_another_kind_or_state_is_not_announced(): void
    {
        self::bootKernel();
        [$owner, $project] = $this->scenario('handler-queued-other');
        $queued = DispatchedEvents::of(self::getContainer(), WorkerRunQueued::class);
        // The guard: a fix run announces, so the recording works.
        $this->report($owner, $project, Uuid::v4(), WorkerRunState::Queued, workKind: 'fix');
        self::assertCount(1, $queued->events());

        $other = $this->report($owner, $project, Uuid::v4(), WorkerRunState::Queued, workKind: 'implement');
        $none = $this->report($owner, $project, Uuid::v4(), WorkerRunState::Queued, workKind: null);
        $running = $this->report($owner, $project, Uuid::v4(), WorkerRunState::Running, workKind: 'fix');

        self::assertTrue($other->newState);
        self::assertTrue($none->newState);
        self::assertTrue($running->newState);
        self::assertCount(1, $queued->events());
    }

    /** @return array{User, Project} */
    private function scenario(string $name): array
    {
        $em = $this->em();
        $owner = $this->user($em, $name.'@example.com');

        return [$owner, $this->project($em, $owner, 'Project '.substr(md5($name), 0, 8))];
    }

    /**
     * @param array<string, mixed>|null                                                                      $resultFields
     * @param array{experiment: string, variant: string, requestedModel: string, switchedFrom: ?string}|null $experiment
     */
    private function report(
        User $owner,
        Project $project,
        Uuid $runKey,
        WorkerRunState $state,
        ?Uuid $cardId = null,
        int $cardNumber = 1,
        ?string $workKind = 'plan',
        ?string $failureReason = null,
        bool $withStart = true,
        ?Uuid $continues = null,
        ?string $resultStatus = null,
        ?array $resultFields = null,
        ?string $resumeSkipped = null,
        ?Uuid $bridgeId = null,
        ?WorkerRunUsageReport $usage = null,
        ?string $workerPool = null,
        ?array $experiment = null,
        ?\DateTimeImmutable $endedAt = null,
        ?string $output = null,
        ?WorkerRunReason $resultReason = null,
        ?Uuid $workRequestId = null,
        ?string $ruleId = null,
        ?string $harness = null,
        ?string $account = null,
        ?string $model = null,
        ?string $harnessSessionId = null,
        ?int $peakContextTokens = null,
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
            subject: WorkSubject::card($cardId ?? Uuid::fromString('0199a0e2-b1f3-7a44-9c11-2d3e4f506172')),
            cardNumber: $cardNumber,
            workRequestId: $workRequestId,
            workKind: $workKind,
            ruleId: $ruleId,
            sessionId: $started ? Uuid::fromString(self::SESSION) : null,
            startedAt: $started ? new \DateTimeImmutable('2026-09-23 10:00:00') : null,
            endedAt: $endedAt ?? ($outcome ? new \DateTimeImmutable('2026-09-23 10:05:00') : null),
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
            output: $output ?? ($outcome ? 'output' : null),
            resultStatus: $resultStatus,
            resultReason: $resultReason,
            resultFields: $resultFields,
            continues: $continues,
            resumeSkipped: $resumeSkipped,
            usage: $usage,
            workerPool: $workerPool,
            experiment: $experiment['experiment'] ?? null,
            variant: $experiment['variant'] ?? null,
            requestedModel: $experiment['requestedModel'] ?? null,
            switchedFrom: $experiment['switchedFrom'] ?? null,
            harness: $harness,
            account: $account,
            model: $model,
            harnessSessionId: $harnessSessionId,
            peakContextTokens: $peakContextTokens,
        ));
    }

    private function storedPeak(WorkerRun $run, string $table, string $key): ?int
    {
        $peak = $this->em()->getConnection()->fetchOne(
            \sprintf('SELECT peak_context_tokens FROM %s WHERE %s = ?', $table, $key),
            [(string) $run->id],
        );
        self::assertNotFalse($peak, 'The row exists.');

        return null === $peak ? null : (int) $peak;
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

    /** Before anything builds the hub: the container hands the replacement only to what it builds afterwards. */
    private function recordRunsChanged(): void
    {
        self::getContainer()->set('mercure.hub.default', new MockHub(
            'http://mercure/.well-known/mercure',
            new StaticTokenProvider('token'),
            function (Update $update): string {
                if (str_contains($update->getData(), WorkerRunChangedPublisher::TYPE)) {
                    $this->runsChanged[] = $update;
                }

                return 'id';
            },
        ));

        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[LiveUpdates::FLAG]->value = true;
        $this->em()->flush();
        $reader = self::getContainer()->get(FeatureFlagReaderInterface::class);
        self::assertInstanceOf(ResetInterface::class, $reader);
        $reader->reset();
    }

    /** @return list<Update> the runs-changed updates sent since the last call */
    private function publishedRunsChanged(): array
    {
        $live = self::getContainer()->get(LiveUpdatePublisher::class);
        self::assertInstanceOf(LiveUpdatePublisher::class, $live);
        $live->publish();
        $published = $this->runsChanged;
        $this->runsChanged = [];

        return $published;
    }

    /** @return array<string, mixed> */
    private function storedExperiment(WorkerRun $run): array
    {
        $row = $this->em()->getConnection()->fetchAssociative(
            'SELECT experiment, variant, requested_model AS "requestedModel", switched_from AS "switchedFrom" FROM bridge_worker_runs WHERE id = ?',
            [(string) $run->id],
        );
        self::assertIsArray($row);

        return $row;
    }

    private function storedPool(WorkerRun $run): ?string
    {
        $pool = $this->em()->getConnection()->fetchOne('SELECT worker_pool FROM bridge_worker_runs WHERE id = ?', [(string) $run->id]);
        self::assertTrue(null === $pool || \is_string($pool));

        return $pool;
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
