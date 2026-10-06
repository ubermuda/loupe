<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ReportSessionUsageCommand;
use App\Module\Bridge\Command\ReportSessionUsageHandler;
use App\Module\Bridge\Command\ReportWorkerRunStateCommand;
use App\Module\Bridge\Command\ReportWorkerRunStateHandler;
use App\Module\Bridge\Command\TimeOutQuietWorkerRunsCommand;
use App\Module\Bridge\Command\TimeOutQuietWorkerRunsHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\EventListener\WorkerRunFactListener;
use App\Module\Bridge\ValueObject\WorkerRunModelUsage;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunUsageReport;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/** Each change goes through a real handler, so the listener sees the flushes production makes. */
final class WorkerRunFactListenerTest extends KernelTestCase
{
    use BridgeScenario;

    private const string SESSION = '5f0c2b1e-8d4a-4c3b-9e2f-1a0b3c4d5e6f';

    public function test_a_state_report_that_closes_a_run_writes_its_fact(): void
    {
        [$owner, $project] = $this->scenario('facts-close');
        $runKey = Uuid::v4();

        $this->report($owner, $project, $runKey, WorkerRunState::Running);
        $run = $this->report($owner, $project, $runKey, WorkerRunState::Succeeded, self::usage(WorkerRunUsageSource::Reported, '0.500000'));

        $fact = $this->fact($run);
        self::assertSame('succeeded', $fact['outcome']);
        self::assertSame('claude-opus', $fact['model']);
        self::assertSame(500000, $fact['cost_micro_usd']);
        self::assertSame(300000, $fact['duration_ms']);
        self::assertSame(10, $fact['tokens_in']);
        self::assertSame('reported', $fact['usage_source']);
    }

    public function test_a_later_report_with_better_usage_changes_the_cost(): void
    {
        [$owner, $project] = $this->scenario('facts-better-usage');
        $run = $this->report($owner, $project, Uuid::v4(), WorkerRunState::Succeeded, self::usage(WorkerRunUsageSource::Estimated, '0.500000'));
        self::assertSame(500000, $this->fact($run)['cost_micro_usd']);

        $this->reportSession($owner, $project, self::usage(WorkerRunUsageSource::Reported, '0.250000'));

        $fact = $this->fact($run);
        self::assertSame(250000, $fact['cost_micro_usd']);
        self::assertSame('reported', $fact['usage_source']);
    }

    public function test_a_stop_writes_the_stopped_outcome(): void
    {
        [$owner, $project] = $this->scenario('facts-stop');
        $runKey = Uuid::v4();
        $run = $this->report($owner, $project, $runKey, WorkerRunState::Running);
        self::assertSame('running', $this->fact($run)['outcome']);

        $this->report($owner, $project, $runKey, WorkerRunState::Stopped);

        self::assertSame('stopped', $this->fact($run)['outcome']);
    }

    public function test_a_timeout_writes_the_timed_out_outcome(): void
    {
        [$owner, $project] = $this->scenario('facts-timeout', new MockClock('2026-09-23 12:00:00'));
        $bridgeId = $this->seedBridge($this->em(), $owner, lastSeenAt: new \DateTimeImmutable('2026-09-23 11:00:00'))->id;
        $run = $this->seedRun($this->em(), $project, new \DateTimeImmutable('2026-09-23 11:00:00'), bridgeId: $bridgeId, state: WorkerRunState::Running, runKey: Uuid::v4());
        self::assertSame('running', $this->fact($run)['outcome']);

        $handler = self::getContainer()->get(TimeOutQuietWorkerRunsHandler::class);
        self::assertInstanceOf(TimeOutQuietWorkerRunsHandler::class, $handler);
        self::assertCount(1, $handler(new TimeOutQuietWorkerRunsCommand()));

        self::assertSame('timed-out', $this->fact($run)['outcome']);
    }

    public function test_a_session_usage_report_fills_the_cost_of_a_run_of_an_interactive_session(): void
    {
        [$owner, $project] = $this->scenario('facts-session');
        $run = $this->report($owner, $project, Uuid::v4(), WorkerRunState::Succeeded);
        self::assertNull($this->fact($run)['cost_micro_usd']);

        $this->reportSession($owner, $project, self::usage(WorkerRunUsageSource::Estimated, '0.250000'));

        $fact = $this->fact($run);
        self::assertSame(250000, $fact['cost_micro_usd']);
        self::assertSame('claude-opus', $fact['model']);
        self::assertSame('estimated', $fact['usage_source']);
    }

    public function test_a_queued_run_gets_a_fact(): void
    {
        [$owner, $project] = $this->scenario('facts-queued');
        $cardId = Uuid::v7();

        $run = $this->report($owner, $project, Uuid::v4(), WorkerRunState::Queued, cardId: $cardId);

        $fact = $this->fact($run);
        self::assertSame('queued', $fact['outcome']);
        self::assertSame('card', $fact['subject_type']);
        self::assertSame((string) $cardId, $fact['subject_id']);
        self::assertNull($fact['started_at']);
        self::assertNull($fact['duration_ms']);
        self::assertNull($fact['cost_micro_usd']);
    }

    /** Doctrine closes, and so clears, the manager when a flush throws. */
    public function test_a_flush_that_throws_leaves_no_run_behind(): void
    {
        [, $project] = $this->scenario('facts-failed-flush');
        $em = $this->em();
        $run = $this->unsavedRun($project);
        $run->experiment = str_repeat('x', WorkerRun::MAX_EXPERIMENT_NAME_LENGTH + 1);
        $em->persist($run);

        try {
            $em->flush();
            self::fail('The database should refuse the experiment.');
        } catch (DriverException) {
        }

        self::assertSame([], $this->listener()->runs);
    }

    public function test_a_service_reset_drops_the_collected_runs(): void
    {
        [, $project] = $this->scenario('facts-reset');
        $em = $this->em();
        $em->persist($this->unsavedRun($project));
        $em->getUnitOfWork()->computeChangeSets();
        $listener = $this->listener();
        $listener->onFlush(new OnFlushEventArgs($em));
        self::assertNotEmpty($listener->runs);

        $resetter = self::getContainer()->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();

        self::assertSame([], $listener->runs);
    }

    private function unsavedRun(Project $project): WorkerRun
    {
        return new WorkerRun($project, Uuid::v7(), Uuid::v7(), 1, 'plan', WorkerRunState::Queued);
    }

    private function listener(): WorkerRunFactListener
    {
        $listener = self::getContainer()->get(WorkerRunFactListener::class);
        self::assertInstanceOf(WorkerRunFactListener::class, $listener);

        return $listener;
    }

    /** @return array{User, Project} */
    private function scenario(string $name, ?MockClock $clock = null): array
    {
        self::bootKernel();
        if (null !== $clock) {
            self::getContainer()->set('clock', $clock);
        }
        $em = $this->em();
        $owner = $this->user($em, $name.'@example.com');

        return [$owner, $this->project($em, $owner, 'Project '.substr(md5($name), 0, 8))];
    }

    private function report(User $owner, Project $project, Uuid $runKey, WorkerRunState $state, ?WorkerRunUsageReport $usage = null, ?Uuid $cardId = null): WorkerRun
    {
        $outcome = $state->isOutcome();
        $started = $outcome || WorkerRunState::Running === $state;
        $handler = self::getContainer()->get(ReportWorkerRunStateHandler::class);
        self::assertInstanceOf(ReportWorkerRunStateHandler::class, $handler);

        $run = $handler(new ReportWorkerRunStateCommand(
            owner: $owner,
            handle: (string) $project->id,
            runKey: $runKey,
            bridgeId: Uuid::fromString('0199a0e2-9d4c-7c5e-9f2a-3b1c6d7e8f90'),
            state: $state,
            at: new \DateTimeImmutable('2026-09-23 10:0'.$state->rank().':00'),
            cardId: $cardId ?? Uuid::fromString('0199a0e2-b1f3-7a44-9c11-2d3e4f506172'),
            cardNumber: 1,
            workRequestId: null,
            workKind: 'plan',
            ruleId: null,
            sessionId: $started ? Uuid::fromString(self::SESSION) : null,
            startedAt: $started ? new \DateTimeImmutable('2026-09-23 10:00:00') : null,
            endedAt: $outcome ? new \DateTimeImmutable('2026-09-23 10:05:00') : null,
            exitCode: WorkerRunState::Succeeded === $state ? 0 : null,
            hasResult: WorkerRunState::Succeeded === $state ? true : null,
            failureReason: null,
            output: $outcome ? 'output' : null,
            resultStatus: null,
            resultReason: null,
            resultFields: null,
            continues: null,
            resumeSkipped: null,
            usage: $usage,
            workerPool: null,
            experiment: null,
            variant: null,
            requestedModel: null,
            switchedFrom: null,
        ))->run;
        self::assertInstanceOf(WorkerRun::class, $run);

        return $run;
    }

    private function reportSession(User $owner, Project $project, WorkerRunUsageReport $usage): void
    {
        $handler = self::getContainer()->get(ReportSessionUsageHandler::class);
        self::assertInstanceOf(ReportSessionUsageHandler::class, $handler);

        self::assertSame(1, $handler(new ReportSessionUsageCommand($owner, (string) $project->id, Uuid::fromString(self::SESSION), [$usage]))->updated);
    }

    private static function usage(WorkerRunUsageSource $source, string $costUsd): WorkerRunUsageReport
    {
        return new WorkerRunUsageReport($source, [new WorkerRunModelUsage('claude-opus', 10, 1, 2, 3, $costUsd)]);
    }

    /** @return array<string, mixed> */
    private function fact(WorkerRun $run): array
    {
        $fact = $this->em()->getConnection()->fetchAssociative(
            'SELECT * FROM bridge_worker_run_facts WHERE run_id = ?',
            [(string) $run->id],
        );
        self::assertIsArray($fact, 'The run has no fact row.');

        return $fact;
    }
}
