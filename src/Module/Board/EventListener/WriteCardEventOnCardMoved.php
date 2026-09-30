<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Event\CardMoved;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Service\CardEventActor;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Writes a `moved` history row when a card changes column. A new rank in the
 * same column is no move a reader follows, so it writes nothing.
 *
 * It persists inside UpdateCardHandler's transaction, which flushes the row
 * with the move. It must never throw, because a throw here refuses the move.
 */
#[AsEventListener]
final readonly class WriteCardEventOnCardMoved
{
    public function __construct(
        private CardEventRepository $cardEvents,
        private CardEventActor $actor,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CardMoved $event): void
    {
        $card = $event->card;
        if ($event->move->fromColumn === $card->column) {
            return;
        }

        try {
            $this->cardEvents->record($card, CardEventKind::Moved, $event->actor, $this->actor->userFor($event->actor), [
                'from' => CardEvent::columnDetail($event->move->fromColumn),
                'to' => CardEvent::columnDetail($card->column),
                'cause' => $event->cause?->detail(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('board.card_event_write_failed', [
                'cardId' => (string) $card->id,
                'projectId' => (string) $card->project->id,
                'exception' => $e,
            ]);
        }
    }
}
