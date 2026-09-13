<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Board\BoardEventType;
use App\Module\Board\Event\BoardColumnDeleted;
use App\Outbox\Entity\OutboxEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Writes one row for a column delete, however many cards it moved. The moved
 * cards get no board.card_moved row, so a bulk move starts no agents.
 *
 * It runs inside DeleteBoardColumnHandler's transaction, so it persists and
 * lets that transaction flush. It must never throw: anything raised here aborts
 * the delete.
 */
#[AsEventListener]
final readonly class WriteOutboxEventOnBoardColumnDeleted
{
    public function __construct(
        private ProjectTopicBuilder $topics,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(BoardColumnDeleted $event): void
    {
        $project = $event->project;

        // A contract with the reader of the outbox, like the card move payload.
        // No label: text a person wrote must never reach an agent.
        $payload = [
            'type' => BoardEventType::COLUMN_DELETED,
            'projectId' => (string) $project->id,
            'subject' => ['type' => 'board_column', 'id' => $event->columnId],
            'actor' => $event->actor->value,
            'slug' => $event->slug,
            'targetSlug' => $event->targetSlug,
            'movedCardIds' => $event->movedCardIds,
        ];

        $this->em->persist(new OutboxEvent(
            project: $project,
            type: BoardEventType::COLUMN_DELETED,
            topic: $this->topics->forProject($project->id ?? throw new \LogicException('Project has no id.')),
            payload: json_encode($payload, \JSON_THROW_ON_ERROR),
        ));
    }
}
