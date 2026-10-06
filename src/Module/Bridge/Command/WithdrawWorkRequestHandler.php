<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\BridgeEventType;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\WorkRequestAnnouncer;
use App\Module\Bridge\Service\WorkRequestPayload;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Outbox\OutboxWriter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Cancels or expires a live work request, and tells the bridges through the
 * outbox. Answers false when the request is unknown or already settled.
 */
final readonly class WithdrawWorkRequestHandler
{
    public function __construct(
        private WorkRequestRepository $workRequests,
        private OutboxWriter $outbox,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private Auditor $auditor,
        private WorkRequestAnnouncer $announcer,
    ) {
    }

    public function __invoke(WithdrawWorkRequestCommand $command): bool
    {
        if (WorkRequestState::Cancelled !== $command->state && WorkRequestState::Expired !== $command->state) {
            throw new \LogicException('A work request withdraws to cancelled or expired.');
        }

        $withdrawn = $this->em->wrapInTransaction(function () use ($command): ?WorkRequest {
            // Locked, so a claim or a settle that races this one waits and then finds the final state.
            $request = $this->workRequests->findOneLocked($command->workRequestId);
            if (null === $request || !$request->withdraw($command->state, $this->clock->now())) {
                return null;
            }
            $this->outbox->write($request->project, BridgeEventType::WORK_REQUEST, WorkRequestPayload::of($request));
            $this->em->flush();

            return $request;
        });
        if (null === $withdrawn) {
            return false;
        }

        $this->auditor->record(
            'bridge.work_request_withdrawn',
            AuditOutcome::Success,
            [
                'workRequestId' => (string) $withdrawn->id,
                'projectId' => (string) $withdrawn->project->id,
                'subjectType' => $withdrawn->subjectType,
                'subjectId' => (string) $withdrawn->subjectId,
                'kind' => $withdrawn->kind,
                'state' => $withdrawn->state->value,
            ],
            new AuditSubject('work_request', (string) $withdrawn->id),
        );
        $this->announcer->announce($withdrawn);

        return true;
    }
}
