<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ReportBridgeRunsCommand;
use App\Module\Bridge\Command\ReportBridgeRunsHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\Repository\WorkerRunStateChangeRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class ReportBridgeRunsHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-09-23 12:00:00';

    public function test_an_open_run_the_inventory_does_not_name_is_lost(): void
    {
        [$owner, $project, $bridgeId] = $this->scenario('inventory-lost');
        $queued = $this->keyedRun($project, $bridgeId, WorkerRunState::Queued);
        $running = $this->keyedRun($project, $bridgeId, WorkerRunState::Running);
        $timedOut = $this->keyedRun($project, $bridgeId, WorkerRunState::TimedOut);

        $changed = $this->handle($owner, $bridgeId, []);

        foreach ([$queued, $running, $timedOut] as $run) {
            self::assertSame(WorkerRunState::Lost, $this->reload($run)->state);
            self::assertSame([['lost', self::NOW]], $this->history($run));
        }
        self::assertCount(3, $changed);
    }

    public function test_a_timed_out_run_the_inventory_names_reopens_in_the_state_it_gives(): void
    {
        [$owner, $project, $bridgeId] = $this->scenario('inventory-reopen');
        $run = $this->keyedRun($project, $bridgeId, WorkerRunState::TimedOut);

        $changed = $this->handle($owner, $bridgeId, [(string) $run->runKey => WorkerRunState::Running]);

        self::assertSame(WorkerRunState::Running, $this->reload($run)->state);
        self::assertSame([['running', self::NOW]], $this->history($run));
        self::assertSame([(string) $run->id], array_map(static fn (WorkerRun $changed): string => (string) $changed->id, $changed));
    }

    /** The inventory reports what the bridge holds, and the state reports carry every move. */
    public function test_an_open_run_the_inventory_names_is_left_alone(): void
    {
        [$owner, $project, $bridgeId] = $this->scenario('inventory-named');
        $run = $this->keyedRun($project, $bridgeId, WorkerRunState::Queued);

        $changed = $this->handle($owner, $bridgeId, [(string) $run->runKey => WorkerRunState::Running]);

        self::assertSame(WorkerRunState::Queued, $this->reload($run)->state);
        self::assertSame([], $this->history($run));
        self::assertSame([], $changed);
    }

    public function test_closed_runs_other_bridges_other_owners_and_old_runs_are_left_alone(): void
    {
        [$owner, $project, $bridgeId] = $this->scenario('inventory-untouched');
        $foreignProject = $this->project($this->em(), $this->user($this->em(), 'inventory-foreign@example.com'), 'Inventory Foreign');
        $closed = $this->keyedRun($project, $bridgeId, WorkerRunState::Succeeded);
        $otherBridge = $this->keyedRun($project, Uuid::v4(), WorkerRunState::Running);
        $otherOwner = $this->keyedRun($foreignProject, $bridgeId, WorkerRunState::Running);
        $noKey = $this->seedRun($this->em(), $project, bridgeId: $bridgeId, state: WorkerRunState::Running);

        $changed = $this->handle($owner, $bridgeId, []);

        self::assertSame(WorkerRunState::Succeeded, $this->reload($closed)->state);
        self::assertSame(WorkerRunState::Running, $this->reload($otherBridge)->state);
        self::assertSame(WorkerRunState::Running, $this->reload($otherOwner)->state);
        self::assertSame(WorkerRunState::Running, $this->reload($noKey)->state);
        self::assertSame([], $changed);
    }

    public function test_a_named_run_the_server_does_not_know_is_ignored(): void
    {
        [$owner, , $bridgeId] = $this->scenario('inventory-unknown');

        self::assertSame([], $this->handle($owner, $bridgeId, [(string) Uuid::v4() => WorkerRunState::Queued]));
    }

    /** @return array{User, Project, Uuid} */
    private function scenario(string $name): array
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock(self::NOW));
        $em = $this->em();
        $owner = $this->user($em, $name.'@example.com');

        return [$owner, $this->project($em, $owner, 'Project '.substr(md5($name), 0, 8)), Uuid::v4()];
    }

    private function keyedRun(Project $project, Uuid $bridgeId, WorkerRunState $state): WorkerRun
    {
        return $this->seedRun($this->em(), $project, bridgeId: $bridgeId, state: $state, runKey: Uuid::v4());
    }

    /**
     * @param array<string, WorkerRunState> $runs
     *
     * @return list<WorkerRun>
     */
    private function handle(User $owner, Uuid $bridgeId, array $runs): array
    {
        $handler = self::getContainer()->get(ReportBridgeRunsHandler::class);
        self::assertInstanceOf(ReportBridgeRunsHandler::class, $handler);

        return $handler(new ReportBridgeRunsCommand($owner, $bridgeId, $runs));
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
