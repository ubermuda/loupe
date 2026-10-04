<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\BridgeEventType;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\WorkRequestAnnouncer;
use App\Module\Bridge\Service\WorkRequestPayload;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Outbox\OutboxWriter;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Opens a work request on a card, and writes the outbox event that offers it to
 * the bridges. The retry of a request whose run ended unfinished names the
 * session of that run, so the bridge resumes it.
 */
final readonly class OpenWorkRequestHandler
{
    public const string INVALID_KIND = 'bridge.work_request.error.invalid_kind';
    public const string INVALID_CAPABILITY = 'bridge.work_request.error.invalid_capability';
    public const string INVALID_RULE = 'bridge.work_request.error.invalid_rule';
    public const string LIVE = 'bridge.work_request.error.live';

    /** Longer than the largest retry backoff of the seeded templates, six hours, so a later request starts fresh. */
    public const string RESUME_WINDOW = '-24 hours';

    public function __construct(
        private WorkRequestRepository $workRequests,
        private OutboxWriter $outbox,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private Auditor $auditor,
        private WorkRequestAnnouncer $announcer,
        private WorkerRunRepository $workerRuns,
    ) {
    }

    public function __invoke(OpenWorkRequestCommand $command): WorkRequest
    {
        $errors = [];
        if (1 !== preg_match(WorkRequest::KIND_PATTERN, $command->kind)) {
            $errors['kind'] = self::INVALID_KIND;
        }
        if (null !== $command->capability && 1 !== preg_match(Bridge::CAPABILITY_PATTERN, $command->capability)) {
            $errors['capability'] = self::INVALID_CAPABILITY;
        }
        if (1 !== preg_match(WorkRequest::RULE_ID_PATTERN, $command->ruleId)) {
            $errors['ruleId'] = self::INVALID_RULE;
        }
        if ([] !== $errors) {
            throw new DomainErrors($errors);
        }

        try {
            $request = $this->em->wrapInTransaction(function () use ($command): ?WorkRequest {
                $this->workRequests->lockLive($command->cardId, $command->kind);
                if ($this->workRequests->hasLive($command->cardId, $command->kind)) {
                    return null;
                }

                $request = new WorkRequest(
                    project: $command->project,
                    cardId: $command->cardId,
                    cardNumber: $command->cardNumber,
                    kind: $command->kind,
                    capability: $command->capability,
                    ruleId: $command->ruleId,
                    createdAt: $this->clock->now(),
                );
                $request->resumeSessionId = $this->sessionToResume($command);
                $this->em->persist($request);
                // The payload names the request, so the row needs its id first.
                $this->em->flush();
                $this->outbox->write($command->project, BridgeEventType::WORK_REQUEST, WorkRequestPayload::of($request));
                $this->em->flush();

                return $request;
            });
        } catch (UniqueConstraintViolationException $e) {
            // A backstop behind the lock. The failed flush closed the entity manager.
            if (!str_contains($e->getMessage(), WorkRequest::LIVE_CARD_KIND_INDEX)) {
                throw $e;
            }
            $request = null;
        }

        if (null === $request) {
            throw new DomainErrors(['card' => self::LIVE]);
        }

        $this->auditor->record(
            'bridge.work_request_opened',
            AuditOutcome::Success,
            [
                'workRequestId' => (string) $request->id,
                'projectId' => (string) $command->project->id,
                'cardId' => (string) $request->cardId,
                'kind' => $request->kind,
                'capability' => $request->capability,
                'ruleId' => $request->ruleId,
            ],
            new AuditSubject('work_request', (string) $request->id),
        );
        $this->announcer->announce($request);

        return $request;
    }

    /**
     * The session of the run of the previous request of the rule, when that run
     * ended unfinished, recently, and no other run of the card came after it.
     */
    private function sessionToResume(OpenWorkRequestCommand $command): ?Uuid
    {
        $previous = $this->workRequests->findLatestOfCardKindRule($command->cardId, $command->kind, $command->ruleId);
        if (null === $previous) {
            return null;
        }
        $run = $this->workerRuns->findLatestOfCard($command->project, $command->cardId);
        if (null === $run
            || WorkerRunState::Unfinished !== $run->state
            || null === $run->workRequestId
            || !$run->workRequestId->equals($previous->id)
            || null === $run->endedAt
            || $run->endedAt < $this->clock->now()->modify(self::RESUME_WINDOW)) {
            return null;
        }

        return $run->sessionId;
    }
}
