<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\BridgeEventType;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\WorkRequestAnnouncer;
use App\Module\Bridge\Service\WorkRequestLease;
use App\Module\Bridge\Service\WorkRequestPayload;
use App\Module\Bridge\ValueObject\WorkRequestRefusal;
use App\Outbox\OutboxWriter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Claims an open work request for one bridge of the caller, with a lease and
 * a fresh claim token. Of two bridges that race, one wins and the other gets
 * already_claimed. The outbox event of the claim lets the others drop the offer.
 */
final readonly class ClaimWorkRequestHandler
{
    public function __construct(
        private BridgeRepository $bridges,
        private WorkRequestRepository $workRequests,
        private WorkRequestLease $lease,
        private OutboxWriter $outbox,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private Auditor $auditor,
        private WorkRequestAnnouncer $announcer,
    ) {
    }

    public function __invoke(ClaimWorkRequestCommand $command): ClaimWorkRequestResult
    {
        $ownerId = (string) ($command->owner->id ?? throw new \LogicException('An authenticated user always has an id.'));

        $claimed = $this->em->wrapInTransaction(function () use ($command, $ownerId): WorkRequest|WorkRequestRefusal {
            // The lock a heartbeat takes, so the projects and capabilities read here stay current until the claim commits.
            $this->bridges->lockForWrite($ownerId, $command->bridgeId);
            $bridge = $this->bridges->findOneByOwnerAndId($command->owner, $command->bridgeId);
            if (null === $bridge) {
                return WorkRequestRefusal::UnknownBridge;
            }

            $request = $this->workRequests->find($command->workRequestId);
            if (null === $request || !self::isOffered($request, $command, $bridge->projects)) {
                return WorkRequestRefusal::NotFound;
            }
            if (!$bridge->canRun($request->capability)) {
                return WorkRequestRefusal::CapabilityMissing;
            }

            $leaseUntil = $this->lease->until($this->clock->now());
            if (!$this->workRequests->claim($command->workRequestId, $command->bridgeId, Uuid::v4(), $leaseUntil)) {
                return WorkRequestRefusal::AlreadyClaimed;
            }
            // The claim writes with native SQL, so the managed copy is stale until this read.
            $claimed = $this->workRequests->findOneLocked($command->workRequestId)
                ?? throw new \LogicException('A claimed work request exists.');
            $this->outbox->write($claimed->project, BridgeEventType::WORK_REQUEST, WorkRequestPayload::of($claimed));
            $this->em->flush();

            return $claimed;
        });
        if ($claimed instanceof WorkRequestRefusal) {
            return new ClaimWorkRequestResult(null, $claimed);
        }

        // Ids only. The claim token proves the claim, so it stays out of the trail.
        $this->auditor->record(
            'bridge.work_request_claimed',
            AuditOutcome::Success,
            [
                'workRequestId' => (string) $claimed->id,
                'projectId' => (string) $claimed->project->id,
                'subjectType' => $claimed->subjectType,
                'subjectId' => (string) $claimed->subjectId,
                'kind' => $claimed->kind,
                'bridgeId' => (string) $command->bridgeId,
                'claims' => $claimed->claims,
            ],
            new AuditSubject('work_request', (string) $claimed->id),
        );
        $this->announcer->announce($claimed);

        return new ClaimWorkRequestResult($claimed);
    }

    /** @param list<string> $followed the RFC 4122 project ids the bridge follows */
    private static function isOffered(WorkRequest $request, ClaimWorkRequestCommand $command, array $followed): bool
    {
        $project = $request->project;

        return null !== $project->id
            && null !== $command->owner->id
            && $command->owner->id->equals($project->owner->id)
            && \in_array($project->id->toRfc4122(), $followed, true);
    }
}
