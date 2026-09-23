<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\View;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\View\WorkerRunListItem;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WorkerRunListItemTest extends TestCase
{
    public function test_a_run_that_never_started_has_no_duration(): void
    {
        $item = new WorkerRunListItem($this->queuedRun(), new \DateTimeImmutable('2026-09-23 10:10:00'));

        self::assertNull($item->durationSeconds);
        self::assertNull($item->duration());
    }

    public function test_an_open_run_counts_up_to_now(): void
    {
        $run = $this->queuedRun();
        $run->markRunning(Uuid::v4(), new \DateTimeImmutable('2026-09-23 10:00:00'));

        $item = new WorkerRunListItem($run, new \DateTimeImmutable('2026-09-23 10:03:12'));

        self::assertSame('3m 12s', $item->duration());
    }

    public function test_a_closed_run_counts_from_its_start_to_its_end(): void
    {
        $run = $this->queuedRun();
        $run->markRunning(Uuid::v4(), new \DateTimeImmutable('2026-09-23 10:00:00'));
        $run->recordOutcome(WorkerRunState::Succeeded, new \DateTimeImmutable('2026-09-23 10:00:21'), 0, null, '');

        $item = new WorkerRunListItem($run, new \DateTimeImmutable('2026-09-23 12:00:00'));

        self::assertSame(WorkerRunState::Succeeded, $item->state);
        self::assertSame('21s', $item->duration());
    }

    public function test_a_run_that_closed_before_it_started_has_no_duration(): void
    {
        $run = $this->queuedRun();
        $run->moveTo(WorkerRunState::Replaced);

        self::assertNull(new WorkerRunListItem($run, new \DateTimeImmutable())->duration());
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
