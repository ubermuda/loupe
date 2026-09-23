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
use App\Module\Bridge\ValueObject\WorkerRunState;
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
        self::assertSame(self::BRIDGE, $result->run->bridgeId->toRfc4122());
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
        $rows = array_map(static fn (WorkerRunStateChange $change): string => $change->state->value, $this->history($run));
        sort($rows);
        self::assertSame(['queued', 'running', 'running', 'timed-out'], $rows);
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
            'spawnFailed' => false,
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

    public function test_a_project_the_owner_does_not_hold_yields_no_run(): void
    {
        self::bootKernel();
        [, $project] = $this->scenario('handler-foreign');
        $stranger = $this->user($this->em(), 'handler-stranger@example.com');

        $result = $this->report($stranger, $project, Uuid::v4(), WorkerRunState::Queued);

        self::assertNull($result->run);
        self::assertFalse($result->newState);
    }

    /** @return array{User, Project} */
    private function scenario(string $name): array
    {
        $em = $this->em();
        $owner = $this->user($em, $name.'@example.com');

        return [$owner, $this->project($em, $owner, 'Project '.substr(md5($name), 0, 8))];
    }

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
    ): ReportWorkerRunStateResult {
        $outcome = \in_array($state, [WorkerRunState::Succeeded, WorkerRunState::Failed, WorkerRunState::NotStarted], true);
        $started = $withStart && ($outcome || WorkerRunState::Running === $state);

        $handler = self::getContainer()->get(ReportWorkerRunStateHandler::class);
        self::assertInstanceOf(ReportWorkerRunStateHandler::class, $handler);

        return $handler(new ReportWorkerRunStateCommand(
            owner: $owner,
            handle: (string) $project->id,
            runKey: $runKey,
            bridgeId: Uuid::fromString(self::BRIDGE),
            state: $state,
            at: new \DateTimeImmutable('2026-09-23 10:0'.$state->rank().':00'),
            cardId: $cardId ?? Uuid::fromString('0199a0e2-b1f3-7a44-9c11-2d3e4f506172'),
            cardNumber: $cardNumber,
            ruleName: $ruleName,
            sessionId: $started ? Uuid::fromString(self::SESSION) : null,
            startedAt: $started ? new \DateTimeImmutable('2026-09-23 10:00:00') : null,
            endedAt: $outcome ? new \DateTimeImmutable('2026-09-23 10:05:00') : null,
            exitCode: match ($state) {
                WorkerRunState::Succeeded => 0,
                WorkerRunState::Failed => 1,
                default => null,
            },
            failureReason: WorkerRunState::NotStarted === $state ? ($failureReason ?? 'no claude') : null,
            output: $outcome ? 'output' : null,
        ));
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
