<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\Service\WorkerRunChangedPublisher;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Withdraws the command that waits on a run. A cancel never changes the hold
 * of the card.
 */
final readonly class CancelBridgeCommandHandler
{
    public const string NOTHING_PENDING = 'bridge.command.error.nothing_pending';

    public function __construct(
        private BridgeCommandRepository $bridgeCommands,
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
            // The row lock orders the cancel with an ack and the expiry sweep, so only one of them settles the command.
            $pending = $this->bridgeCommands->findPendingForRunLocked($run);
            if (null === $pending || !$pending->settle(BridgeCommandState::Cancelled, null, $this->clock->now())) {
                return null;
            }
            $this->em->flush();

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
