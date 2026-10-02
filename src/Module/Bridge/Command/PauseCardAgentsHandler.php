<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\BridgeEventType;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\Service\WorkerRunChangedPublisher;
use App\Outbox\OutboxWriter;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * A person pauses the agents on a card. The hold and its outbox event commit
 * together, so a bridge never learns of a hold that rolled back.
 */
final readonly class PauseCardAgentsHandler
{
    public const string ALREADY_PAUSED = 'bridge.card_hold.error.already_paused';

    public function __construct(
        private CardHolds $cardHolds,
        private OutboxWriter $outbox,
        private WorkerRunChangedPublisher $runsChanged,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(PauseCardAgentsCommand $command): void
    {
        $project = $command->project;
        $cardId = $command->cardId;

        // Returns the refusal, because an exception inside the closure closes the entity manager.
        $refusal = $this->em->wrapInTransaction(function () use ($command, $project, $cardId): ?string {
            // The lock that card moves and resumes take, so the read below stays true until the commit.
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);
            if ($this->cardHolds->isHeld($project, $cardId)) {
                return self::ALREADY_PAUSED;
            }

            $this->cardHolds->hold($project, $cardId, $command->requestedBy);
            $this->outbox->write($project, BridgeEventType::CARD_HELD, [
                'type' => BridgeEventType::CARD_HELD,
                'subject' => ['type' => 'card', 'id' => (string) $cardId],
                'projectId' => (string) $project->id,
                'actor' => 'human',
            ]);
            $this->em->flush();

            return null;
        });

        if (null !== $refusal) {
            throw new DomainErrors(['card' => $refusal]);
        }

        $this->auditor->record(
            'bridge.card_held',
            AuditOutcome::Success,
            ['projectId' => (string) $project->id, 'cardId' => (string) $cardId],
            new AuditSubject('card', (string) $cardId),
        );
        $this->runsChanged->runsChanged($project);
    }
}
