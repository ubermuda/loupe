<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\Service\WorkerRunChangedPublisher;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Settles a pending command with the answer of its bridge. A command that is
 * already settled stays as it is, so the bridge can repeat an ack safely. A
 * resume the bridge took releases the hold of its card, unless a person asked
 * for a stop of the card since. A resume the bridge never took keeps the hold.
 */
final readonly class AcknowledgeBridgeCommandHandler
{
    public function __construct(
        private BridgeCommandRepository $bridgeCommands,
        private CardHolds $cardHolds,
        private WorkerRunChangedPublisher $runsChanged,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(AcknowledgeBridgeCommandCommand $command): AcknowledgeBridgeCommandResult
    {
        if (!\in_array($command->state, [BridgeCommandState::Done, BridgeCommandState::Refused], true)) {
            throw new \LogicException('A bridge settles a command as done or refused.');
        }

        $result = $this->em->wrapInTransaction(function () use ($command): AcknowledgeBridgeCommandResult {
            // The row lock orders two acks and the expiry sweep, so only one of them settles the command.
            $bridgeCommand = $this->bridgeCommands->findOneForBridgeLocked($command->owner, $command->bridgeId, $command->commandId);
            if (null === $bridgeCommand) {
                return new AcknowledgeBridgeCommandResult(null, false);
            }

            $settled = $bridgeCommand->settle($command->state, $command->reason, $this->clock->now());
            // Also on a late ack, because the bridge resumed the run even when a cancel or the expiry settled the row first.
            $project = $bridgeCommand->project;
            $cardId = $bridgeCommand->workerRun->cardId;
            if (BridgeCommandKind::ResumeRun === $bridgeCommand->kind && BridgeCommandState::Done === $command->state
                && !$this->bridgeCommands->hasLiveStopForCardSince($project, $cardId, $bridgeCommand->requestedAt)) {
                $this->cardHolds->release($project, [$cardId]);
            }

            return new AcknowledgeBridgeCommandResult($bridgeCommand, $settled);
        });

        $settled = $result->command;
        if ($result->settled && null !== $settled) {
            // No reason, because it may carry text a person wrote.
            $this->auditor->record(
                'bridge.command_settled',
                AuditOutcome::Success,
                [
                    'commandId' => (string) $settled->id,
                    'state' => $settled->state->value,
                    'kind' => $settled->kind->value,
                    'projectId' => (string) $settled->project->id,
                    'runId' => (string) $settled->workerRun->id,
                    'bridgeId' => (string) $settled->bridgeId,
                ],
                new AuditSubject('bridge_command', (string) $settled->id),
            );
            $this->runsChanged->runsChanged($settled->project);
        }

        return $result;
    }
}
