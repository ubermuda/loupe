<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\Repository\CardHoldRepository;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\Service\WorkerRunChangedPublisher;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Withdraws the command that waits on a run. When no stop of the card waits
 * any more, and the stop that wrote the card hold was withdrawn, the hold goes.
 */
final readonly class CancelBridgeCommandHandler
{
    public const string NOTHING_PENDING = 'bridge.command.error.nothing_pending';

    public function __construct(
        private BridgeCommandRepository $bridgeCommands,
        private CardHoldRepository $cardHolds,
        private CardHolds $holds,
        private WorkerRunChangedPublisher $runsChanged,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(CancelBridgeCommandCommand $command): BridgeCommand
    {
        $run = $command->run;

        $cancelled = $this->em->wrapInTransaction(function () use ($run): ?BridgeCommand {
            // The same lock a hold takes, so two cancels of one card cannot both see the other stop as pending.
            $this->em->lock($run->project, LockMode::PESSIMISTIC_WRITE);
            // The row lock orders the cancel with an ack and the expiry sweep, so only one of them settles the command.
            $pending = $this->bridgeCommands->findPendingForRunLocked($run);
            if (null === $pending || !$pending->settle(BridgeCommandState::Cancelled, null, $this->clock->now())) {
                return null;
            }
            $this->em->flush();

            if (BridgeCommandKind::StopRun === $pending->kind && !$this->bridgeCommands->hasPendingStopForCard($run->project, $run->cardId)) {
                $hold = $this->cardHolds->findOneOfCard($run->project, $run->cardId);
                // A stop of another run that the bridge took since the hold keeps the card held.
                if (null !== $hold && null !== $hold->stoppedRun && $this->bridgeCommands->lastStopWasCancelled($hold->stoppedRun)
                    && !$this->bridgeCommands->hasLiveStopForCardSince($run->project, $run->cardId, $hold->heldAt)) {
                    $this->holds->release($run->project, [$run->cardId]);
                }
            }

            return $pending;
        });

        if (null === $cancelled) {
            throw new DomainErrors(['run' => self::NOTHING_PENDING]);
        }

        // After the commit, so a rollback leaves no record. No reason, because a person wrote it.
        $this->auditor->record(
            'bridge.command_cancelled',
            AuditOutcome::Success,
            [
                'commandId' => (string) $cancelled->id,
                'kind' => $cancelled->kind->value,
                'projectId' => (string) $run->project->id,
                'runId' => (string) $run->id,
                'bridgeId' => (string) $cancelled->bridgeId,
            ],
            new AuditSubject('bridge_command', (string) $cancelled->id),
        );
        $this->runsChanged->runsChanged($run->project);

        return $cancelled;
    }
}
