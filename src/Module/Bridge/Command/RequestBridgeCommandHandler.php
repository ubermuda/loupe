<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\BridgeEventType;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Service\BridgeCommandPayload;
use App\Module\Bridge\Service\BridgeCommandTtl;
use App\Outbox\OutboxWriter;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Stores a person's request to the bridge that holds a worker run, and writes
 * the outbox event that carries it to the bridge.
 */
final readonly class RequestBridgeCommandHandler
{
    public const string NO_BRIDGE = 'bridge.command.error.no_bridge';
    public const string UNKNOWN_BRIDGE = 'bridge.command.error.unknown_bridge';
    public const string PENDING = 'bridge.command.error.pending';
    public const string REASON_TOO_LONG = 'bridge.command.error.reason_too_long';

    public function __construct(
        private BridgeRepository $bridges,
        private BridgeCommandRepository $bridgeCommands,
        private BridgeCommandTtl $ttl,
        private OutboxWriter $outbox,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(RequestBridgeCommandCommand $command): BridgeCommand
    {
        $run = $command->run;
        $bridgeId = $run->bridgeId;
        if (null === $bridgeId) {
            throw new DomainErrors(['run' => self::NO_BRIDGE]);
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

                if (null === $this->bridges->findOneByOwnerAndId($owner, $bridgeId)) {
                    return self::UNKNOWN_BRIDGE;
                }
                if ($this->bridgeCommands->hasPendingForRun($run)) {
                    return self::PENDING;
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
                );
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

        // After the commit, so a rollback leaves no record. No reason, because a person wrote it.
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

        return $result;
    }
}
