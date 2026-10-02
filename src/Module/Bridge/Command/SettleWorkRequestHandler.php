<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\BridgeEventType;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\WorkRequestPayload;
use App\Module\Bridge\ValueObject\WorkRequestRefusal;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Outbox\OutboxWriter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Settles a claimed work request with the result of the bridge that holds the
 * claim. The claim token fences the claim: after a lapse and a new claim, the
 * old holder gets claim_lost and the new holder's result stands. The same
 * result sent again answers the same and changes nothing.
 */
final readonly class SettleWorkRequestHandler
{
    public function __construct(
        private WorkRequestRepository $workRequests,
        private OutboxWriter $outbox,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(SettleWorkRequestCommand $command): SettleWorkRequestResult
    {
        if (WorkRequestState::Done !== $command->state && WorkRequestState::Refused !== $command->state) {
            throw new \LogicException('A bridge settles a work request as done or refused.');
        }

        $result = $this->em->wrapInTransaction(function () use ($command): SettleWorkRequestResult {
            // Locked, so a withdrawal, the lapse sweep or a second result waits and then reads the final state.
            $request = $this->workRequests->findOneLocked($command->workRequestId);
            if (null === $request || null === $command->owner->id || !$command->owner->id->equals($request->project->owner->id)) {
                return new SettleWorkRequestResult(null, refusal: WorkRequestRefusal::NotFound);
            }
            if (!$this->holds($request, $command)) {
                return new SettleWorkRequestResult(null, refusal: WorkRequestRefusal::ClaimLost);
            }
            if ($command->state === $request->state) {
                return new SettleWorkRequestResult($request);
            }
            if (!$request->settle($command->state, $command->reason, $this->clock->now())) {
                return new SettleWorkRequestResult(null, refusal: WorkRequestRefusal::ClaimLost);
            }
            $this->outbox->write($request->project, BridgeEventType::WORK_REQUEST, WorkRequestPayload::of($request));
            $this->em->flush();

            return new SettleWorkRequestResult($request, settled: true);
        });

        $settled = $result->request;
        if ($result->settled && null !== $settled) {
            $this->auditor->record(
                'bridge.work_request_settled',
                AuditOutcome::Success,
                [
                    'workRequestId' => (string) $settled->id,
                    'projectId' => (string) $settled->project->id,
                    'cardId' => (string) $settled->cardId,
                    'kind' => $settled->kind,
                    'bridgeId' => (string) $command->bridgeId,
                    'state' => $settled->state->value,
                    'reason' => $settled->reason,
                ],
                new AuditSubject('work_request', (string) $settled->id),
            );
        }

        return $result;
    }

    /** Settle keeps the bridge and the token, so a repeat after the settlement still matches. */
    private function holds(WorkRequest $request, SettleWorkRequestCommand $command): bool
    {
        return \in_array($request->state, [WorkRequestState::Claimed, WorkRequestState::Done, WorkRequestState::Refused], true)
            && $command->bridgeId->equals($request->bridgeId)
            && $command->claimToken->equals($request->claimToken);
    }
}
