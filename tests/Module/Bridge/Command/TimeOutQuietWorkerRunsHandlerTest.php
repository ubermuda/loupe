<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\TimeOutQuietWorkerRunsCommand;
use App\Module\Bridge\Command\TimeOutQuietWorkerRunsHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\Repository\WorkerRunStateChangeRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/** The default heartbeat interval is 60 seconds, so a bridge goes quiet after 180. */
final class TimeOutQuietWorkerRunsHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-09-23 12:00:00';

    public function test_the_open_runs_of_a_quiet_bridge_time_out(): void
    {
        [$owner, $project] = $this->scenario('sweep-quiet');
        $bridgeId = $this->seedBridge($this->em(), $owner, lastSeenAt: new \DateTimeImmutable('2026-09-23 11:56:59'))->id;
        $queued = $this->openRun($project, $bridgeId, WorkerRunState::Queued);
        $running = $this->openRun($project, $bridgeId, WorkerRunState::Running);

        $changed = $this->sweep();

        foreach ([$queued, $running] as $run) {
            self::assertSame(WorkerRunState::TimedOut, $this->reload($run)->state);
            self::assertSame([['timed-out', self::NOW]], $this->history($run));
        }
        self::assertCount(2, $changed);
    }

    public function test_the_runs_of_a_live_bridge_stay_open(): void
    {
        [$owner, $project] = $this->scenario('sweep-live');
        $bridgeId = $this->seedBridge($this->em(), $owner, lastSeenAt: new \DateTimeImmutable('2026-09-23 11:57:00'))->id;
        $run = $this->openRun($project, $bridgeId, WorkerRunState::Running);

        self::assertSame([], $this->sweep());
        self::assertSame(WorkerRunState::Running, $this->reload($run)->state);
    }

    public function test_closed_runs_of_a_quiet_bridge_stay_as_they_are(): void
    {
        [$owner, $project] = $this->scenario('sweep-closed');
        $bridgeId = $this->seedBridge($this->em(), $owner, lastSeenAt: new \DateTimeImmutable('2026-09-23 10:00:00'))->id;
        $runs = array_map(
            fn (WorkerRunState $state): WorkerRun => $this->openRun($project, $bridgeId, $state),
            [WorkerRunState::Succeeded, WorkerRunState::Dropped, WorkerRunState::TimedOut, WorkerRunState::Lost],
        );

        self::assertSame([], $this->sweep());
        foreach ($runs as $run) {
            self::assertSame($run->state, $this->reload($run)->state);
        }
    }

    /** The row is per owner, so another account's heartbeat under the same bridge id says nothing. */
    public function test_another_owners_live_row_does_not_keep_the_run_open(): void
    {
        [$owner, $project] = $this->scenario('sweep-other-owner');
        $bridgeId = $this->seedBridge($this->em(), $owner, lastSeenAt: new \DateTimeImmutable('2026-09-23 11:00:00'))->id;
        $this->seedBridge($this->em(), $this->user($this->em(), 'sweep-other@example.com'), $bridgeId, lastSeenAt: new \DateTimeImmutable(self::NOW));
        $run = $this->openRun($project, $bridgeId, WorkerRunState::Running);

        $this->sweep();

        self::assertSame(WorkerRunState::TimedOut, $this->reload($run)->state);
    }

    public function test_a_bridge_with_no_heartbeat_row_is_quiet_once_the_run_is_old_enough(): void
    {
        [, $project] = $this->scenario('sweep-no-row');
        $old = $this->openRun($project, Uuid::v4(), WorkerRunState::Queued, new \DateTimeImmutable('2026-09-23 11:56:59'));
        $fresh = $this->openRun($project, Uuid::v4(), WorkerRunState::Queued, new \DateTimeImmutable('2026-09-23 11:57:00'));

        $this->sweep();

        self::assertSame(WorkerRunState::TimedOut, $this->reload($old)->state);
        self::assertSame(WorkerRunState::Queued, $this->reload($fresh)->state);
    }

    /** @return array{User, Project} */
    private function scenario(string $name): array
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock(self::NOW));
        $em = $this->em();
        $owner = $this->user($em, $name.'@example.com');

        return [$owner, $this->project($em, $owner, 'Project '.substr(md5($name), 0, 8))];
    }

    private function openRun(
        Project $project,
        Uuid $bridgeId,
        WorkerRunState $state,
        \DateTimeImmutable $receivedAt = new \DateTimeImmutable('2026-09-23 11:00:00'),
    ): WorkerRun {
        return $this->seedRun($this->em(), $project, $receivedAt, bridgeId: $bridgeId, state: $state, runKey: Uuid::v4());
    }

    /** @return list<WorkerRun> */
    private function sweep(): array
    {
        $handler = self::getContainer()->get(TimeOutQuietWorkerRunsHandler::class);
        self::assertInstanceOf(TimeOutQuietWorkerRunsHandler::class, $handler);

        return $handler(new TimeOutQuietWorkerRunsCommand());
    }

    private function reload(WorkerRun $run): WorkerRun
    {
        $this->em()->clear();
        $reloaded = $this->em()->find(WorkerRun::class, $run->id);
        self::assertInstanceOf(WorkerRun::class, $reloaded);

        return $reloaded;
    }

    /** @return list<array{string, string}> */
    private function history(WorkerRun $run): array
    {
        $repository = self::getContainer()->get(WorkerRunStateChangeRepository::class);
        self::assertInstanceOf(WorkerRunStateChangeRepository::class, $repository);

        return array_map(
            static fn (WorkerRunStateChange $change): array => [$change->state->value, $change->at->format('Y-m-d H:i:s')],
            $repository->findForRun($this->reload($run)),
        );
    }
}
