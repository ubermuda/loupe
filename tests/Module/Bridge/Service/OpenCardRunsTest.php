<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\OpenCardRuns;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\View\OpenCardRun;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class OpenCardRunsTest extends TestCase
{
    private Project $project;

    #[\Override]
    protected function setUp(): void
    {
        $this->project = new Project(new User(fullName: 'Riley Chen', email: 'riley@example.com', password: 'x'), 'Runs');
    }

    public function test_a_card_keeps_its_highest_ranked_open_run(): void
    {
        $card = Uuid::v7();
        $runs = $this->openRuns(
            $this->workerRun($card, WorkerRunState::Queued, '2026-09-01 09:00:00', ruleName: 'tech-design'),
            $this->workerRun($card, WorkerRunState::Running, '2026-09-01 10:00:00', startedAt: '2026-09-01 10:01:00', ruleName: 'Session', kind: WorkerRunKind::Interactive),
        );

        self::assertCount(1, $runs);
        self::assertSame(WorkerRunState::Running, $runs[0]->state);
        self::assertSame(WorkerRunKind::Interactive, $runs[0]->kind);
        self::assertSame('Session', $runs[0]->ruleName);
        self::assertTrue($card->equals($runs[0]->cardId));
    }

    public function test_a_tie_on_rank_keeps_the_run_that_started_waiting_first(): void
    {
        $card = Uuid::v7();
        $runs = $this->openRuns(
            $this->workerRun($card, WorkerRunState::Queued, '2026-09-01 10:00:00', ruleName: 'later'),
            $this->workerRun($card, WorkerRunState::Queued, '2026-09-01 09:00:00', ruleName: 'earlier'),
        );

        self::assertCount(1, $runs);
        self::assertSame('earlier', $runs[0]->ruleName);
    }

    public function test_a_running_run_counts_from_its_start_and_a_waiting_run_from_its_arrival(): void
    {
        $running = Uuid::v7();
        $noStart = Uuid::v7();
        $resumed = Uuid::v7();
        $preparing = Uuid::v7();
        $runs = $this->openRuns(
            $this->workerRun($running, WorkerRunState::Running, '2026-09-01 09:00:00', startedAt: '2026-09-01 09:30:00'),
            $this->workerRun($preparing, WorkerRunState::Preparing, '2026-09-01 05:00:00', startedAt: '2026-09-01 05:15:00'),
            $this->workerRun($noStart, WorkerRunState::Running, '2026-09-01 08:00:00'),
            $this->workerRun($resumed, WorkerRunState::Resumed, '2026-09-01 07:00:00', startedAt: '2026-09-01 06:00:00'),
        );

        $since = [];
        foreach ($runs as $run) {
            $since[(string) $run->cardId] = $run->since->format('H:i');
        }
        self::assertSame('09:30', $since[(string) $running]);
        self::assertSame('08:00', $since[(string) $noStart]);
        self::assertSame('07:00', $since[(string) $resumed]);
        self::assertSame('05:15', $since[(string) $preparing]);
    }

    public function test_runs_sort_by_rank_then_by_the_longest_wait(): void
    {
        $queuedEarly = Uuid::v7();
        $queuedLate = Uuid::v7();
        $resumed = Uuid::v7();
        $runningLate = Uuid::v7();
        $runningEarly = Uuid::v7();
        $runs = $this->openRuns(
            $this->workerRun($queuedLate, WorkerRunState::Queued, '2026-09-01 11:00:00'),
            $this->workerRun($runningLate, WorkerRunState::Running, '2026-09-01 08:00:00', startedAt: '2026-09-01 10:00:00'),
            $this->workerRun($queuedEarly, WorkerRunState::Queued, '2026-09-01 07:00:00'),
            $this->workerRun($resumed, WorkerRunState::Resumed, '2026-09-01 12:00:00'),
            $this->workerRun($runningEarly, WorkerRunState::Running, '2026-09-01 08:00:00', startedAt: '2026-09-01 09:00:00'),
        );

        self::assertSame(
            [(string) $runningEarly, (string) $runningLate, (string) $resumed, (string) $queuedEarly, (string) $queuedLate],
            array_map(static fn (OpenCardRun $run): string => (string) $run->cardId, $runs),
        );
    }

    public function test_no_open_run_gives_an_empty_list(): void
    {
        self::assertSame([], $this->openRuns());
    }

    /** @return list<OpenCardRun> */
    private function openRuns(WorkerRun ...$runs): array
    {
        $repository = $this->createStub(WorkerRunRepository::class);
        $repository->method('findOpenOfProject')->willReturn(array_values($runs));

        return new OpenCardRuns($repository)->forProject($this->project);
    }

    private function workerRun(
        Uuid $cardId,
        WorkerRunState $state,
        string $receivedAt,
        ?string $startedAt = null,
        string $ruleName = 'implement',
        WorkerRunKind $kind = WorkerRunKind::Worker,
    ): WorkerRun {
        return new WorkerRun(
            project: $this->project,
            bridgeId: Uuid::v7(),
            cardId: $cardId,
            cardNumber: 1,
            ruleName: $ruleName,
            state: $state,
            startedAt: null === $startedAt ? null : new \DateTimeImmutable($startedAt),
            receivedAt: new \DateTimeImmutable($receivedAt),
            kind: $kind,
        );
    }
}
