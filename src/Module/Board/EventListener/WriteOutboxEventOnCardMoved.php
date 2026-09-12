<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Board\BoardEventType;
use App\Module\Board\Event\CardMoved;
use App\Outbox\Entity\OutboxEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Turns a card move into a durable outbox row, so the agent bound to the
 * project can act on it. Every move writes one, because the rule that picks the
 * moves worth acting on belongs to whatever reads the outbox.
 *
 * It runs inside UpdateCardHandler's transaction, so it persists and lets that
 * transaction flush. It must never throw: anything raised here aborts the card
 * move it was told about.
 */
#[AsEventListener]
final readonly class WriteOutboxEventOnCardMoved
{
    public function __construct(
        private ProjectTopicBuilder $topics,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(CardMoved $event): void
    {
        $card = $event->card;
        $project = $card->project;

        // Every key and every value is a contract with the reader of the
        // outbox, so a rename here is a breaking change there. Identifiers the
        // server generated, and no title and no body: text a person wrote must
        // never reach an agent through a directive.
        $payload = [
            'type' => BoardEventType::CARD_MOVED,
            'subject' => ['type' => 'card', 'id' => (string) $card->id],
            'projectId' => (string) $project->id,
            'cardNumber' => $card->number,
            'fromStatus' => $event->move->fromStatus->value,
            'toStatus' => $card->status->value,
        ];

        $this->em->persist(new OutboxEvent(
            project: $project,
            type: BoardEventType::CARD_MOVED,
            topic: $this->topics->forProject($project->id ?? throw new \LogicException('Project has no id.')),
            payload: json_encode($payload, \JSON_THROW_ON_ERROR),
        ));
    }
}
