<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\BridgeEventType;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\BridgeCommandPayload;
use App\Module\Bridge\Service\BridgeCommandTtl;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\Service\WorkerRunChangedPublisher;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Outbox\OutboxWriter;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Stores a request of a person or of Loupe to the bridge that holds a worker
 * run, and writes the outbox event that carries it to the bridge. A stop ends
 * one run and holds nothing. A resume waits until a person lets the agents on
 * the card run again.
 */
final readonly class RequestBridgeCommandHandler
{
    public const string NO_BRIDGE = 'bridge.command.error.no_bridge';
    public const string UNKNOWN_BRIDGE = 'bridge.command.error.unknown_bridge';
    public const string PENDING = 'bridge.command.error.pending';
    public const string REASON_TOO_LONG = 'bridge.command.error.reason_too_long';
    public const string NOT_CONTROLLABLE = 'bridge.command.error.not_controllable';
    public const string NO_SESSION = 'bridge.command.error.no_session';
    public const string NOT_RESUMABLE = 'bridge.command.error.not_resumable';
    public const string NOT_STOPPABLE = 'bridge.command.error.not_stoppable';
    public const string BRIDGE_OUTDATED = 'bridge.command.error.bridge_outdated';
    public const string NOT_A_COMMAND = 'bridge.command.error.not_a_command';
    public const string NOT_RERUNNABLE = 'bridge.command.error.not_rerunnable';
    public const string CARD_HELD = 'bridge.command.error.card_held';

    public function __construct(
        private BridgeRepository $bridges,
        private BridgeCommandRepository $bridgeCommands,
        private BridgeCommandTtl $ttl,
        private OutboxWriter $outbox,
        private CardHolds $cardHolds,
        private WorkerRunChangedPublisher $runsChanged,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private Auditor $auditor,
        private WorkRequestRepository $workRequests,
    ) {
    }

    public function __invoke(RequestBridgeCommandCommand $command): BridgeCommand
    {
        $run = $command->run;
        $bridgeId = $run->bridgeId;
        if (null === $bridgeId) {
            throw new DomainErrors(['run' => self::NO_BRIDGE]);
        }
        $refusal = $this->refusalOf($run, $command->kind);
        if (null !== $refusal) {
            throw new DomainErrors(['run' => $refusal]);
        }
        if (null !== $command->reason && mb_strlen($command->reason) > BridgeCommand::MAX_REASON_LENGTH) {
            throw new DomainErrors(['reason' => self::REASON_TOO_LONG]);
        }
        $reason = null === $command->reason || '' === trim($command->reason) ? null : trim($command->reason);
        $owner = $run->project->owner;
        $ownerId = (string) ($owner->id ?? throw new \LogicException('A project owner always has an id.'));

        try {
            $result = $this->em->wrapInTransaction(function () use ($command, $run, $bridgeId, $owner, $ownerId, $reason): BridgeCommand|string {
                // The same lock the heartbeat takes, so two requests for one run
                // cannot both miss the pending read.
                $this->bridges->lockForWrite($ownerId, $bridgeId);

                $bridge = $this->bridges->findOneByOwnerAndId($owner, $bridgeId);
                if (null === $bridge) {
                    return self::UNKNOWN_BRIDGE;
                }
                if (!$bridge->takesCommands() || (BridgeCommandKind::RerunCommand === $command->kind && !$bridge->takesReruns())) {
                    return self::BRIDGE_OUTDATED;
                }
                if ($this->bridgeCommands->hasPendingForRun($run)) {
                    return self::PENDING;
                }
                // The lock a pause takes, so a pause cannot commit between this read and the resume.
                // Always after the bridge lock: no holder of a project lock takes a bridge lock.
                if (BridgeCommandKind::ResumeRun === $command->kind) {
                    $this->em->lock($run->project, LockMode::PESSIMISTIC_WRITE);
                    if ($this->isCardHeld($run)) {
                        return self::CARD_HELD;
                    }
                }

                $now = $this->clock->now();
                $bridgeCommand = new BridgeCommand(
                    owner: $owner,
                    bridgeId: $bridgeId,
                    project: $run->project,
                    workerRun: $run,
                    kind: $command->kind,
                    requestedBy: $command->requestedBy,
                    requestedAt: $now,
                    expiresAt: $this->ttl->expiresAt($now),
                    reason: $reason,
                    cause: $command->cause,
                );
                $request = null === $run->workRequestId ? null : $this->workRequests->findOneOfSubject($run->workRequestId, $run->project, $run->subject());
                if (null !== $request) {
                    $bridgeCommand->context = $request->context;
                    $bridgeCommand->model = $request->model;
                    $bridgeCommand->effort = $request->effort;
                }
                $this->em->persist($bridgeCommand);
                // The payload names the command, so the row needs its id first.
                $this->em->flush();
                $this->outbox->write($run->project, BridgeEventType::COMMAND, BridgeCommandPayload::of($bridgeCommand));
                $this->em->flush();

                return $bridgeCommand;
            });
        } catch (UniqueConstraintViolationException $e) {
            if (!str_contains($e->getMessage(), BridgeCommand::PENDING_RUN_INDEX)) {
                throw $e;
            }

            throw new DomainErrors(['run' => self::PENDING]);
        }

        if (\is_string($result)) {
            throw new DomainErrors(['run' => $result]);
        }

        // After the commit, so a rollback leaves no record. No reason, because a person can write it.
        $this->auditor->record(
            'bridge.command_requested',
            AuditOutcome::Success,
            [
                'commandId' => (string) $result->id,
                'kind' => $result->kind->value,
                'projectId' => (string) $run->project->id,
                'runId' => (string) $run->id,
                'bridgeId' => (string) $bridgeId,
            ],
            new AuditSubject('bridge_command', (string) $result->id),
        );
        $this->runsChanged->runsChanged($run->project);

        return $result;
    }

    /** A hold is about a card, so a run about any other subject is never held. */
    private function isCardHeld(WorkerRun $run): bool
    {
        $cardId = $run->cardId();

        return null !== $cardId && $this->cardHolds->isHeld($run->project, $cardId);
    }

    private function refusalOf(WorkerRun $run, BridgeCommandKind $kind): ?string
    {
        if (WorkerRunKind::Interactive === $run->kind) {
            return self::NOT_CONTROLLABLE;
        }

        return match ($kind) {
            BridgeCommandKind::StopRun => $run->state->isStoppable() ? null : self::NOT_STOPPABLE,
            BridgeCommandKind::ResumeRun => match (true) {
                null === $run->sessionId => self::NO_SESSION,
                !$run->state->isResumable() => self::NOT_RESUMABLE,
                $this->isCardHeld($run) => self::CARD_HELD,
                default => null,
            },
            BridgeCommandKind::RerunCommand => match (true) {
                WorkerRunKind::Command !== $run->kind => self::NOT_A_COMMAND,
                !$run->state->isRerunnable() => self::NOT_RERUNNABLE,
                default => null,
            },
            BridgeCommandKind::CollectSessionUsage => self::NOT_CONTROLLABLE,
        };
    }
}
