<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Entity;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WorkerRunTest extends TestCase
{
    public function test_a_queued_run_holds_no_session_start_or_end(): void
    {
        $run = $this->queuedRun();

        self::assertSame(WorkerRunState::Queued, $run->state);
        self::assertNull($run->sessionId);
        self::assertNull($run->startedAt);
        self::assertNull($run->endedAt);
    }

    public function test_marking_it_running_records_the_session_and_the_start(): void
    {
        $run = $this->queuedRun();
        $sessionId = Uuid::v4();
        $startedAt = new \DateTimeImmutable('2026-09-23 10:00:00');

        $run->markRunning($sessionId, $startedAt);

        self::assertSame(WorkerRunState::Running, $run->state);
        self::assertSame($sessionId, $run->sessionId);
        self::assertSame($startedAt, $run->startedAt);
    }

    public function test_an_outcome_records_the_end_and_what_the_worker_left(): void
    {
        $run = $this->queuedRun();
        $endedAt = new \DateTimeImmutable('2026-09-23 10:05:00');

        $run->recordOutcome(WorkerRunState::Failed, $endedAt, 2, null, 'it broke');

        self::assertSame(WorkerRunState::Failed, $run->state);
        self::assertSame($endedAt, $run->endedAt);
        self::assertSame(2, $run->exitCode);
        self::assertNull($run->failureReason);
        self::assertSame('it broke', $run->output);
    }

    public function test_an_open_state_is_not_an_outcome(): void
    {
        $this->expectException(\LogicException::class);

        $this->queuedRun()->recordOutcome(WorkerRunState::Running, new \DateTimeImmutable(), null, null, '');
    }

    public function test_moving_to_a_state_changes_the_state_alone(): void
    {
        $run = $this->queuedRun();

        $run->moveTo(WorkerRunState::Replaced);

        self::assertSame(WorkerRunState::Replaced, $run->state);
        self::assertNull($run->endedAt);
    }

    private function queuedRun(): WorkerRun
    {
        return new WorkerRun(
            project: new Project(new User('Alice A', 'alice@example.com', 'x'), 'My project'),
            bridgeId: Uuid::v7(),
            cardId: Uuid::v7(),
            cardNumber: 7,
            ruleName: 'plan',
            state: WorkerRunState::Queued,
            runKey: Uuid::v7(),
        );
    }
}
