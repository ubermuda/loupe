<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\BridgeEventType;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\WorkRequestAnnouncer;
use App\Module\Bridge\Service\WorkRequestPayload;
use App\Outbox\OutboxWriter;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Opens a work request on a card, and writes the outbox event that offers it to the bridges. */
final readonly class OpenWorkRequestHandler
{
    public const string INVALID_KIND = 'bridge.work_request.error.invalid_kind';
    public const string INVALID_CAPABILITY = 'bridge.work_request.error.invalid_capability';
    public const string INVALID_RULE = 'bridge.work_request.error.invalid_rule';
    public const string LIVE = 'bridge.work_request.error.live';

    public function __construct(
        private WorkRequestRepository $workRequests,
        private OutboxWriter $outbox,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private Auditor $auditor,
        private WorkRequestAnnouncer $announcer,
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
}
