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
 * A person or an agent lets the agents on a card run again. The delete and its outbox
 * event commit together, so a bridge never learns of a release that rolled back.
 */
final readonly class ReleaseCardAgentsHandler
{
    public const string NOT_PAUSED = 'bridge.card_hold.error.not_paused';

    public function __construct(
        private CardHolds $cardHolds,
        private OutboxWriter $outbox,
        private WorkerRunChangedPublisher $runsChanged,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(ReleaseCardAgentsCommand $command): void
    {
        $project = $command->project;
        $cardId = $command->cardId;

        // Returns the refusal, because an exception inside the closure closes the entity manager.
        $refusal = $this->em->wrapInTransaction(function () use ($command, $project, $cardId): ?string {
            // The lock a pause takes, so a pause and a release of one card run one after the other.
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);
            if (0 === $this->cardHolds->release($project, [$cardId])) {
                return self::NOT_PAUSED;
            }

            $this->outbox->write($project, BridgeEventType::CARD_RELEASED, [
                'type' => BridgeEventType::CARD_RELEASED,
                'subject' => ['type' => 'card', 'id' => (string) $cardId],
                'projectId' => (string) $project->id,
                'actor' => $command->actor,
            ]);
            $this->em->flush();

            return null;
        });

        if (null !== $refusal) {
            throw new DomainErrors(['card' => $refusal]);
        }

        $this->auditor->record(
            'bridge.card_released',
            AuditOutcome::Success,
            ['projectId' => (string) $project->id, 'cardId' => (string) $cardId],
            new AuditSubject('card', (string) $cardId),
        );
        $this->runsChanged->runsChanged($project);
    }
}
