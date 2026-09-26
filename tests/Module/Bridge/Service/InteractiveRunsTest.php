<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkerRunStateChangeRepository;
use App\Module\Bridge\Service\InteractiveRuns;
use App\Module\Bridge\Service\WorkerRunChangedPublisher;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class InteractiveRunsTest extends KernelTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-09-23 12:00:00';

    public function test_open_starts_a_running_interactive_run_with_one_state(): void
    {
        $project = $this->scenario('interactive-open');
        $cardId = Uuid::v7();
        $sessionId = Uuid::v4();

        $run = $this->runs()->open($project, $cardId, 17, $sessionId, 'Pairing on the design');

        $run = $this->reload($run);
        self::assertSame(WorkerRunKind::Interactive, $run->kind);
        self::assertSame(WorkerRunState::Running, $run->state);
        self::assertNull($run->bridgeId);
        self::assertNull($run->runKey);
        self::assertSame($cardId->toRfc4122(), $run->cardId->toRfc4122());
        self::assertSame(17, $run->cardNumber);
        self::assertSame($sessionId->toRfc4122(), $run->sessionId?->toRfc4122());
        self::assertSame('Pairing on the design', $run->ruleName);
        self::assertSame(self::NOW, $run->startedAt?->format('Y-m-d H:i:s'));
        self::assertNull($run->endedAt);
        self::assertSame([['running', self::NOW]], $this->history($run));
    }

    public function test_open_keeps_the_bridge_that_launched_the_session(): void
    {
        $project = $this->scenario('interactive-open-bridge');
        $bridgeId = Uuid::v4();

        $run = $this->runs()->open($project, Uuid::v7(), 17, Uuid::v4(), 'design', $bridgeId);

        self::assertSame($bridgeId->toRfc4122(), $this->reload($run)->bridgeId?->toRfc4122());
    }

    public function test_one_bridge_can_launch_two_sessions_on_one_card_at_one_moment(): void
    {
        $project = $this->scenario('interactive-open-bridge-twice');
        $cardId = Uuid::v7();
        $bridgeId = Uuid::v4();

        $first = $this->runs()->open($project, $cardId, 3, Uuid::v4(), 'design', $bridgeId);
        $second = $this->runs()->open($project, $cardId, 3, Uuid::v4(), 'review', $bridgeId);

        self::assertNotNull($first->id);
        self::assertFalse($first->id->equals($second->id));
    }

    /** The session started, so its own open finds the run that the bridge opened. */
    public function test_an_open_from_the_session_takes_over_the_run_the_bridge_opened(): void
    {
        $project = $this->scenario('interactive-takeover');
        $cardId = Uuid::v7();
        $sessionId = Uuid::v4();
        $bridgeId = Uuid::v4();

        $launched = $this->runs()->open($project, $cardId, 3, $sessionId, 'design', $bridgeId);
        $opened = $this->runs()->open($project, $cardId, 3, $sessionId, 'card_run_open');

        self::assertNotNull($launched->id);
        self::assertTrue($launched->id->equals($opened->id));
        $run = $this->reload($opened);
        self::assertSame('design', $run->ruleName);
        self::assertSame($bridgeId->toRfc4122(), $run->bridgeId?->toRfc4122());
        self::assertSame([['running', self::NOW]], $this->history($run));
    }

    public function test_a_launch_of_a_session_that_has_a_run_changes_nothing(): void
    {
        $project = $this->scenario('interactive-launch-again');
        $cardId = Uuid::v7();
        $sessionId = Uuid::v4();
        $bridgeId = Uuid::v4();

        [$launched, $created] = $this->runs()->recordLaunch($project, $cardId, 3, $sessionId, 'design', $bridgeId);
        self::assertTrue($created);
        $this->runs()->closeOnMove($project, [$cardId]);

        [$again, $created] = $this->runs()->recordLaunch($project, $cardId, 3, $sessionId, 'design', $bridgeId);

        self::assertFalse($created);
        self::assertEquals($launched->id, $again->id);

        $failedSession = Uuid::v4();
        [$failed] = $this->runs()->recordLaunchFailure($project, $cardId, 3, $failedSession, 'design', $bridgeId, 'launcher exited 1', new \DateTimeImmutable(self::NOW));
        [$late, $created] = $this->runs()->recordLaunch($project, $cardId, 3, $failedSession, 'design', $bridgeId);

        self::assertFalse($created);
        self::assertEquals($failed->id, $late->id);
        self::assertSame(2, $this->runCount($project));
        self::assertSame(WorkerRunState::Closed, $this->reload($again)->state);
    }

    public function test_a_launch_failure_records_a_run_that_never_started(): void
    {
        $project = $this->scenario('interactive-launch-failure');
        $cardId = Uuid::v7();
        $sessionId = Uuid::v4();
        $bridgeId = Uuid::v4();
        $at = new \DateTimeImmutable('2026-09-23 11:59:00');

        [$run, $created] = $this->runs()->recordLaunchFailure($project, $cardId, 8, $sessionId, 'design', $bridgeId, 'launcher exited 127', $at);

        self::assertTrue($created);
        $run = $this->reload($run);
        self::assertSame(WorkerRunKind::Interactive, $run->kind);
        self::assertSame(WorkerRunState::NotStarted, $run->state);
        self::assertSame($bridgeId->toRfc4122(), $run->bridgeId?->toRfc4122());
        self::assertSame($sessionId->toRfc4122(), $run->sessionId?->toRfc4122());
        self::assertSame(8, $run->cardNumber);
        self::assertSame('design', $run->ruleName);
        self::assertSame('launcher exited 127', $run->failureReason);
        self::assertNull($run->startedAt);
        self::assertNull($run->exitCode);
        self::assertSame('2026-09-23 11:59:00', $run->endedAt?->format('Y-m-d H:i:s'));
        self::assertSame(self::NOW, $run->receivedAt->format('Y-m-d H:i:s'));
        self::assertSame([['not-started', '2026-09-23 11:59:00']], $this->history($run));
        self::assertFalse($this->runs()->hasOpenRun($project, $cardId));
    }

    public function test_a_launch_failure_clips_a_long_reason(): void
    {
        $project = $this->scenario('interactive-launch-failure-long');

        [$run] = $this->runs()->recordLaunchFailure($project, Uuid::v7(), 8, Uuid::v4(), 'design', Uuid::v4(), str_repeat('é', WorkerRun::MAX_FAILURE_REASON_LENGTH + 5), new \DateTimeImmutable(self::NOW));

        self::assertSame(str_repeat('é', WorkerRun::MAX_FAILURE_REASON_LENGTH), $this->reload($run)->failureReason);
    }

    /** The session opened its run, so it started whatever the launcher said. */
    public function test_a_launch_failure_leaves_an_open_run_of_the_session_alone(): void
    {
        $project = $this->scenario('interactive-launch-failure-open');
        $cardId = Uuid::v7();
        $sessionId = Uuid::v4();
        $open = $this->runs()->open($project, $cardId, 3, $sessionId, 'card_run_open');

        [$run, $created] = $this->runs()->recordLaunchFailure($project, $cardId, 3, $sessionId, 'design', Uuid::v4(), 'launcher exited 1', new \DateTimeImmutable(self::NOW));

        self::assertFalse($created);
        self::assertEquals($open->id, $run->id);
        $run = $this->reload($run);
        self::assertSame(WorkerRunState::Running, $run->state);
        self::assertNull($run->failureReason);
        self::assertNull($run->bridgeId);
        self::assertSame(1, $this->runCount($project));
    }

    /** A retry of the report, or a report that arrives after the session closed, adds no row. */
    public function test_a_launch_failure_of_a_session_that_has_a_run_adds_no_row(): void
    {
        $project = $this->scenario('interactive-launch-failure-again');
        $cardId = Uuid::v7();
        $sessionId = Uuid::v4();
        $bridgeId = Uuid::v4();
        $at = new \DateTimeImmutable(self::NOW);

        [$first] = $this->runs()->recordLaunchFailure($project, $cardId, 3, $sessionId, 'design', $bridgeId, 'launcher exited 1', $at);
        [$again, $created] = $this->runs()->recordLaunchFailure($project, $cardId, 3, $sessionId, 'design', $bridgeId, 'launcher exited 1', $at);

        self::assertFalse($created);
        self::assertEquals($first->id, $again->id);

        $closedSession = Uuid::v4();
        $closed = $this->runs()->open($project, $cardId, 3, $closedSession, 'design', $bridgeId);
        $this->runs()->close($project, $cardId, $closedSession);
        [$late, $created] = $this->runs()->recordLaunchFailure($project, $cardId, 3, $closedSession, 'design', $bridgeId, 'launcher exited 1', $at);

        self::assertFalse($created);
        self::assertEquals($closed->id, $late->id);
        self::assertSame(WorkerRunState::Closed, $this->reload($late)->state);
        self::assertSame(2, $this->runCount($project));
    }

    public function test_a_second_open_of_the_same_session_returns_the_open_run(): void
    {
        $project = $this->scenario('interactive-reopen');
        $cardId = Uuid::v7();
        $sessionId = Uuid::v4();

        $first = $this->runs()->open($project, $cardId, 3, $sessionId, 'design');
        $second = $this->runs()->open($project, $cardId, 3, $sessionId, 'design');

        self::assertNotNull($first->id);
        self::assertTrue($first->id->equals($second->id));
        self::assertSame([['running', self::NOW]], $this->history($second));
    }

    public function test_an_open_after_a_close_starts_a_new_run(): void
    {
        $project = $this->scenario('interactive-open-again');
        $cardId = Uuid::v7();
        $sessionId = Uuid::v4();

        $first = $this->runs()->open($project, $cardId, 3, $sessionId, 'design');
        $this->runs()->close($project, $cardId, $sessionId);
        $second = $this->runs()->open($project, $cardId, 3, $sessionId, 'design');

        self::assertNotNull($first->id);
        self::assertFalse($first->id->equals($second->id));
        self::assertSame(WorkerRunState::Running, $this->reload($second)->state);
    }

    public function test_a_blank_name_is_refused(): void
    {
        $project = $this->scenario('interactive-blank');

        $this->expectException(\InvalidArgumentException::class);
        $this->runs()->open($project, Uuid::v7(), 3, Uuid::v4(), '   ');
    }

    public function test_a_name_at_the_limit_is_kept_whole(): void
    {
        $project = $this->scenario('interactive-limit');

        $run = $this->runs()->open($project, Uuid::v7(), 3, Uuid::v4(), str_repeat('é', WorkerRun::MAX_RULE_NAME_LENGTH));

        self::assertSame(str_repeat('é', WorkerRun::MAX_RULE_NAME_LENGTH), $this->reload($run)->ruleName);
    }

    public function test_a_name_over_the_limit_is_refused(): void
    {
        $project = $this->scenario('interactive-long');

        $this->expectException(\InvalidArgumentException::class);
        $this->runs()->open($project, Uuid::v7(), 3, Uuid::v4(), str_repeat('é', WorkerRun::MAX_RULE_NAME_LENGTH + 1));
    }

    public function test_close_ends_the_run_and_appends_closed(): void
    {
        $project = $this->scenario('interactive-close');
        $cardId = Uuid::v7();
        $sessionId = Uuid::v4();
        $this->runs()->open($project, $cardId, 3, $sessionId, 'design');

        $closed = $this->runs()->close($project, $cardId, $sessionId);

        self::assertNotNull($closed);
        $run = $this->reload($closed);
        self::assertSame(WorkerRunState::Closed, $run->state);
        self::assertSame(self::NOW, $run->endedAt?->format('Y-m-d H:i:s'));
        self::assertSame([['running', self::NOW], ['closed', self::NOW]], $this->history($run));
    }

    public function test_closing_twice_changes_nothing_the_second_time(): void
    {
        $project = $this->scenario('interactive-close-twice');
        $cardId = Uuid::v7();
        $sessionId = Uuid::v4();
        $this->runs()->open($project, $cardId, 3, $sessionId, 'design');
        $this->runs()->close($project, $cardId, $sessionId);

        $again = $this->runs()->close($project, $cardId, $sessionId);

        self::assertNotNull($again);
        self::assertSame(WorkerRunState::Closed, $this->reload($again)->state);
        self::assertSame([['running', self::NOW], ['closed', self::NOW]], $this->history($again));
    }

    /** The entity manager still holds the run as running, so only the database says it is closed. */
    public function test_a_close_of_a_run_closed_elsewhere_appends_no_second_closed(): void
    {
        $project = $this->scenario('interactive-close-stale');
        $cardId = Uuid::v7();
        $sessionId = Uuid::v4();
        $run = $this->runs()->open($project, $cardId, 3, $sessionId, 'design');
        $this->closeBehindTheEntityManager($run);

        $closed = $this->runs()->close($project, $cardId, $sessionId);

        self::assertEquals($run->id, $closed?->id);
        self::assertSame(WorkerRunState::Closed, $closed?->state);
        self::assertSame([['running', self::NOW], ['closed', self::NOW]], $this->history($run));
    }

    public function test_a_close_by_id_of_a_run_closed_elsewhere_appends_no_second_closed(): void
    {
        $project = $this->scenario('interactive-close-by-id-stale');
        $run = $this->runs()->open($project, Uuid::v7(), 3, Uuid::v4(), 'design');
        $runId = $run->id ?? throw new \LogicException('An opened run has an id.');
        $this->closeBehindTheEntityManager($run);

        $closed = $this->runs()->closeById($project, $runId);

        self::assertEquals($runId, $closed?->id);
        self::assertSame(WorkerRunState::Closed, $closed?->state);
        self::assertSame([['running', self::NOW], ['closed', self::NOW]], $this->history($run));
    }

    public function test_close_with_no_run_answers_null(): void
    {
        $project = $this->scenario('interactive-close-none');

        self::assertNull($this->runs()->close($project, Uuid::v7(), Uuid::v4()));
    }

    public function test_close_by_id_closes_only_an_interactive_run_of_the_project(): void
    {
        $project = $this->scenario('interactive-close-by-id');
        $other = $this->project($this->em(), $this->user($this->em(), 'interactive-close-by-id-other@example.com'), 'Other');
        $run = $this->runs()->open($project, Uuid::v7(), 3, Uuid::v4(), 'design');
        $worker = $this->seedRun($this->em(), $project, state: WorkerRunState::Running, runKey: Uuid::v7());
        self::assertNotNull($run->id);
        self::assertNotNull($worker->id);

        self::assertNull($this->runs()->closeById($other, $run->id));
        self::assertNull($this->runs()->closeById($project, $worker->id));
        self::assertSame(WorkerRunState::Running, $this->reload($worker)->state);

        $closed = $this->runs()->closeById($project, $run->id);

        self::assertNotNull($closed);
        self::assertSame(WorkerRunState::Closed, $this->reload($closed)->state);
    }

    public function test_a_move_closes_every_open_interactive_run_of_the_card_alone(): void
    {
        $project = $this->scenario('interactive-move');
        $cardId = Uuid::v7();
        $first = $this->runs()->open($project, $cardId, 3, Uuid::v4(), 'design');
        $second = $this->runs()->open($project, $cardId, 3, Uuid::v4(), 'review');
        $otherCard = $this->runs()->open($project, Uuid::v7(), 4, Uuid::v4(), 'design');
        $worker = $this->seedRun($this->em(), $project, cardId: $cardId, state: WorkerRunState::Running, runKey: Uuid::v7());

        $this->runs()->closeOnMove($project, [$cardId]);

        foreach ([$first, $second] as $run) {
            self::assertSame([['running', self::NOW], ['closed', self::NOW]], $this->history($run));
        }
        self::assertSame(WorkerRunState::Running, $this->reload($otherCard)->state);
        self::assertSame(WorkerRunState::Running, $this->reload($worker)->state);
    }

    public function test_a_move_of_several_cards_closes_the_runs_of_each(): void
    {
        $project = $this->scenario('interactive-move-many');
        $firstCard = Uuid::v7();
        $secondCard = Uuid::v7();
        $first = $this->runs()->open($project, $firstCard, 3, Uuid::v4(), 'design');
        $second = $this->runs()->open($project, $secondCard, 4, Uuid::v4(), 'design');
        $untouched = $this->runs()->open($project, Uuid::v7(), 5, Uuid::v4(), 'design');

        $this->runs()->closeOnMove($project, [$firstCard, $secondCard]);

        self::assertSame(WorkerRunState::Closed, $this->reload($first)->state);
        self::assertSame(WorkerRunState::Closed, $this->reload($second)->state);
        self::assertSame(WorkerRunState::Running, $this->reload($untouched)->state);
    }

    public function test_has_open_run_reads_only_open_interactive_runs_of_the_card(): void
    {
        $project = $this->scenario('interactive-has-open');
        $cardId = Uuid::v7();
        $sessionId = Uuid::v4();
        $this->seedRun($this->em(), $project, cardId: $cardId, state: WorkerRunState::Running, runKey: Uuid::v7());

        self::assertFalse($this->runs()->hasOpenRun($project, $cardId));

        $this->runs()->open($project, $cardId, 3, $sessionId, 'design');
        self::assertTrue($this->runs()->hasOpenRun($project, $cardId));
        self::assertFalse($this->runs()->hasOpenRun($project, Uuid::v7()));

        $this->runs()->close($project, $cardId, $sessionId);
        self::assertFalse($this->runs()->hasOpenRun($project, $cardId));
    }

    private function scenario(string $name): Project
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock(self::NOW));
        $em = $this->em();

        return $this->project($em, $this->user($em, $name.'@example.com'), 'Project '.substr(md5($name), 0, 8));
    }

    private function runs(): InteractiveRuns
    {
        $container = self::getContainer();
        $workerRuns = $container->get(WorkerRunRepository::class);
        $clock = $container->get('clock');
        $publisher = $container->get(WorkerRunChangedPublisher::class);
        self::assertInstanceOf(WorkerRunRepository::class, $workerRuns);
        self::assertInstanceOf(ClockInterface::class, $clock);
        self::assertInstanceOf(WorkerRunChangedPublisher::class, $publisher);

        return new InteractiveRuns($workerRuns, $this->searchIndexer(), $this->em(), $clock, $publisher);
    }

    private function closeBehindTheEntityManager(WorkerRun $run): void
    {
        $connection = $this->em()->getConnection();
        $connection->executeStatement("UPDATE bridge_worker_runs SET state = 'closed', ended_at = :at WHERE id = :id", ['at' => self::NOW, 'id' => (string) $run->id]);
        $connection->executeStatement(
            "INSERT INTO bridge_worker_run_states (id, run_id, state, at, received_at) VALUES (:changeId, :id, 'closed', :at, :at)",
            ['changeId' => Uuid::v7()->toRfc4122(), 'id' => (string) $run->id, 'at' => self::NOW],
        );
        self::assertSame(WorkerRunState::Running, $run->state, 'the entity manager must still hold the stale copy');
    }

    private function runCount(Project $project): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_worker_runs WHERE project_id = ?', [(string) $project->id]);
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
