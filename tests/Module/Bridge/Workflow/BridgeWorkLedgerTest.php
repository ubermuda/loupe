<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Workflow;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\WithdrawKind;
use App\Module\Workflow\Contract\WorkLedger;
use App\Module\Workflow\Contract\WorkState;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BridgeWorkLedgerTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_live_lists_the_open_and_claimed_work_of_the_card_oldest_first(): void
    {
        $project = $this->scenario('ledger-live@example.com');
        $cardId = Uuid::v7();
        $newer = $this->seedWorkRequest($this->em(), $project, $cardId, 'fix', createdAt: new \DateTimeImmutable('2026-10-01 13:00:00'), ruleId: 'fix-rule');
        $older = $this->seedWorkRequest($this->em(), $project, $cardId, 'implement', state: WorkRequestState::Claimed, createdAt: new \DateTimeImmutable('2026-10-01 12:00:00'), reopenedAt: new \DateTimeImmutable('2026-10-01 12:30:00'));
        $this->seedWorkRequest($this->em(), $project, $cardId, 'review', state: WorkRequestState::Done);
        $this->seedWorkRequest($this->em(), $project, Uuid::v7(), 'implement');

        $live = $this->ledger()->live($cardId);

        self::assertSame([$older->id?->toRfc4122(), $newer->id?->toRfc4122()], array_map(static fn ($view): string => $view->id->toRfc4122(), $live));
        self::assertSame('implement', $live[0]->kind);
        self::assertSame('implement-on-entry', $live[0]->ruleId);
        self::assertSame(WorkState::Claimed, $live[0]->state);
        self::assertSame('2026-10-01 12:30:00', $live[0]->reopenedAt?->format('Y-m-d H:i:s'));
        self::assertSame(WorkState::Open, $live[1]->state);
        self::assertNull($live[1]->reopenedAt);
    }

    public function test_find_reads_a_request_in_any_state_and_answers_null_for_an_unknown_one(): void
    {
        $project = $this->scenario('ledger-find@example.com');
        $request = $this->seedWorkRequest($this->em(), $project, state: WorkRequestState::Refused);
        $request->reason = 'failed';
        $request->settledAt = new \DateTimeImmutable('2026-10-01 14:00:00');
        $this->em()->flush();

        $view = $this->ledger()->find($request->id ?? throw new \LogicException('A flushed request has an id.'));

        self::assertNotNull($view);
        self::assertSame(WorkState::Refused, $view->state);
        self::assertSame('failed', $view->reason);
        self::assertSame('2026-10-01 14:00:00', $view->settledAt?->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-01 12:00:00', $view->createdAt->format('Y-m-d H:i:s'));
        self::assertNull($this->ledger()->find(Uuid::v7()));
    }

    public function test_withdraw_cancels_or_expires_a_live_request_once(): void
    {
        $project = $this->scenario('ledger-withdraw@example.com');
        $cancelled = $this->seedWorkRequest($this->em(), $project, kind: 'implement');
        $expired = $this->seedWorkRequest($this->em(), $project, kind: 'fix');
        $cancelledId = $cancelled->id ?? throw new \LogicException('A flushed request has an id.');
        $expiredId = $expired->id ?? throw new \LogicException('A flushed request has an id.');

        self::assertTrue($this->ledger()->withdraw($cancelledId, WithdrawKind::Cancelled));
        self::assertTrue($this->ledger()->withdraw($expiredId, WithdrawKind::Expired));
        self::assertFalse($this->ledger()->withdraw($cancelledId, WithdrawKind::Cancelled));
        self::assertFalse($this->ledger()->withdraw(Uuid::v7(), WithdrawKind::Expired));

        $this->em()->clear();
        self::assertSame(WorkRequestState::Cancelled, $this->em()->find(WorkRequest::class, $cancelledId)?->state);
        self::assertSame(WorkRequestState::Expired, $this->em()->find(WorkRequest::class, $expiredId)?->state);
    }

    public function test_the_latest_continuation_honours_the_time_and_the_rule(): void
    {
        $project = $this->scenario('ledger-continuation@example.com');
        $cardId = Uuid::v7();
        $first = $this->seedRun($this->em(), $project, cardId: $cardId);
        $stopped = $this->continuation($project, $cardId, $first, WorkerRunState::Failed, '2026-10-01 12:00:00');

        $run = $this->ledger()->latestContinuation($cardId, null, null);

        self::assertNotNull($run);
        self::assertSame('failed', $run->state);
        self::assertTrue($run->stop);
        self::assertNotNull($this->ledger()->latestContinuation($cardId, new \DateTimeImmutable('2026-10-01 12:00:00'), null));
        self::assertNull($this->ledger()->latestContinuation($cardId, new \DateTimeImmutable('2026-10-01 12:00:01'), null));
        self::assertNotNull($this->ledger()->latestContinuation($cardId, null, 'any-rule'));
        self::assertNull($this->ledger()->latestContinuation(Uuid::v7(), null, null));

        $this->continuation($project, $cardId, $stopped, WorkerRunState::Succeeded, '2026-10-01 13:00:00');
        $latest = $this->ledger()->latestContinuation($cardId, null, null);
        self::assertSame('succeeded', $latest?->state);
        self::assertFalse($latest->stop);
    }

    public function test_an_open_worker_run_on_the_card_counts(): void
    {
        $project = $this->scenario('ledger-open-run@example.com');
        $cardId = Uuid::v7();
        $run = $this->seedRun($this->em(), $project, cardId: $cardId, state: WorkerRunState::Running);

        self::assertTrue($this->openOnCard($run, $project, $cardId));
        self::assertFalse($this->openOnCard($run, $project, Uuid::v7()));
        self::assertFalse($this->ledger()->isOpenWorkerOnCard('not-a-uuid', $this->projectId($project), $cardId));
        self::assertFalse($this->ledger()->isOpenWorkerOnCard((string) Uuid::v7(), $this->projectId($project), $cardId));
    }

    public function test_a_closed_run_counts_only_through_an_open_worker_of_its_session(): void
    {
        $project = $this->scenario('ledger-session@example.com');
        $cardId = Uuid::v7();
        $childId = Uuid::v7();
        $closed = $this->seedRun($this->em(), $project, cardId: $childId, state: WorkerRunState::Succeeded);
        self::assertFalse($this->openOnCard($closed, $project, $cardId));

        $session = $closed->sessionId ?? throw new \LogicException('A seeded worker run has a session.');
        $this->seedRun($this->em(), $project, cardId: $cardId, state: WorkerRunState::Running)->markRunning($session, new \DateTimeImmutable('2026-10-01 12:00:00'));
        $this->em()->flush();

        self::assertTrue($this->openOnCard($closed, $project, $cardId));
        self::assertFalse($this->openOnCard($closed, $project, Uuid::v7()));
    }

    public function test_an_interactive_run_on_the_card_does_not_count(): void
    {
        $project = $this->scenario('ledger-interactive@example.com');
        $cardId = Uuid::v7();
        $run = $this->seedRun($this->em(), $project, cardId: $cardId, state: WorkerRunState::Running, kind: WorkerRunKind::Interactive);

        self::assertFalse($this->openOnCard($run, $project, $cardId));
    }

    public function test_is_held_follows_the_hold_of_the_card(): void
    {
        $project = $this->scenario('ledger-held@example.com');
        $cardId = Uuid::v7();
        $holds = static::getContainer()->get(CardHolds::class);
        self::assertInstanceOf(CardHolds::class, $holds);

        self::assertFalse($this->ledger()->isHeld($this->projectId($project), $cardId));

        $holds->hold($project, $cardId, null);

        self::assertTrue($this->ledger()->isHeld($this->projectId($project), $cardId));
        self::assertFalse($this->ledger()->isHeld($this->projectId($project), Uuid::v7()));
    }

    /** @param non-empty-string $email */
    private function scenario(string $email): Project
    {
        self::bootKernel();

        return $this->project($this->em(), $this->user($this->em(), $email), 'Ledger');
    }

    private function ledger(): WorkLedger
    {
        $ledger = static::getContainer()->get(WorkLedger::class);
        self::assertInstanceOf(WorkLedger::class, $ledger);

        return $ledger;
    }

    private function projectId(Project $project): Uuid
    {
        return $project->id ?? throw new \LogicException('A flushed project has an id.');
    }

    private function openOnCard(WorkerRun $run, Project $project, Uuid $cardId): bool
    {
        return $this->ledger()->isOpenWorkerOnCard((string) $run->id, $this->projectId($project), $cardId);
    }

    private function continuation(Project $project, Uuid $cardId, WorkerRun $continues, WorkerRunState $state, string $receivedAt): WorkerRun
    {
        $run = $this->seedRun($this->em(), $project, new \DateTimeImmutable($receivedAt), cardId: $cardId, state: $state);
        $run->continuesRun = $continues;
        $this->em()->flush();

        return $run;
    }
}
