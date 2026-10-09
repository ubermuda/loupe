<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

use App\Module\Bridge\Command\WithdrawWorkRequestCommand;
use App\Module\Bridge\Command\WithdrawWorkRequestHandler;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Workflow\Contract\RunView;
use App\Module\Workflow\Contract\WithdrawKind;
use App\Module\Workflow\Contract\WorkLedger;
use App\Module\Workflow\Contract\WorkState;
use App\Module\Workflow\Contract\WorkView;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Lazy;
use Symfony\Component\Uid\Uuid;

/** Lazy, because the withdraw handler reaches the auditor, which a test replaces after boot. */
#[AsAlias(WorkLedger::class)]
#[Lazy(WorkLedger::class)]
final readonly class BridgeWorkLedger implements WorkLedger
{
    public function __construct(
        private WorkRequestRepository $workRequests,
        private WorkerRunRepository $workerRuns,
        private WithdrawWorkRequestHandler $withdrawWorkRequest,
        private CardHolds $cardHolds,
        private ProjectRepository $projects,
    ) {
    }

    #[\Override]
    public function live(Uuid $cardId): array
    {
        return array_map(self::view(...), $this->workRequests->findLiveForCard($cardId));
    }

    #[\Override]
    public function find(Uuid $requestId): ?WorkView
    {
        $request = $this->workRequests->find($requestId);

        return null === $request ? null : self::view($request);
    }

    #[\Override]
    public function withdraw(Uuid $requestId, WithdrawKind $kind): bool
    {
        return ($this->withdrawWorkRequest)(new WithdrawWorkRequestCommand(
            $requestId,
            WithdrawKind::Cancelled === $kind ? WorkRequestState::Cancelled : WorkRequestState::Expired,
        ));
    }

    #[\Override]
    public function latestContinuation(Uuid $cardId, ?\DateTimeImmutable $since, ?string $ruleId): ?RunView
    {
        $run = $this->workerRuns->findLatestContinuationOfCard($cardId, $since, $ruleId);

        return null === $run ? null : new RunView($run->state->value, $run->state->isStop());
    }

    #[\Override]
    public function isOpenWorkerOnCard(string $runId, Uuid $projectId, Uuid $cardId): bool
    {
        $run = $this->workerRuns->findOneByIdAndProjectId($runId, (string) $projectId);
        if (null === $run) {
            return false;
        }
        if (WorkerRunKind::Worker === $run->kind && $run->state->isOpen() && true === $run->cardId()?->equals($cardId)) {
            return true;
        }

        // The cause prefers a run on the child, so a resumed session can name an older child run.
        return null !== $run->sessionId
            && $this->workerRuns->hasOpenWorkerOfSessionOnCard($run->project, $run->sessionId, $cardId);
    }

    /** @param non-empty-list<Uuid> $cardIds */
    #[\Override]
    public function restartClock(Uuid $projectId, array $cardIds, \DateTimeImmutable $now): void
    {
        $this->workRequests->restartClockOfOpenForCards($projectId, $cardIds, $now);
    }

    #[\Override]
    public function isHeld(Uuid $projectId, Uuid $cardId): bool
    {
        $project = $this->projects->find($projectId) ?? throw new \LogicException('A held card belongs to a stored project.');

        return $this->cardHolds->isHeld($project, $cardId);
    }

    private static function view(WorkRequest $request): WorkView
    {
        return new WorkView(
            $request->id ?? throw new \LogicException('A persisted work request has an id.'),
            $request->ruleId,
            $request->kind,
            WorkState::from($request->state->value),
            $request->reason,
            $request->createdAt,
            $request->reopenedAt,
            $request->settledAt,
        );
    }
}
