<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Entity\Card;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Event\CardMoved;
use App\Module\Board\Event\CardParentChanged;
use App\Module\Board\Repository\CardRepository;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * An epic face counts its finished children and shows its Backlog children,
 * so a child that joins, leaves, finishes, reopens, or enters, leaves or
 * moves inside the Backlog changes the face of its epic.
 */
final readonly class DispatchEpicChangedOnChildChange
{
    public function __construct(
        private CardRepository $cards,
        private EventDispatcherInterface $events,
    ) {
    }

    #[AsEventListener]
    public function onCardParentChanged(CardParentChanged $event): void
    {
        foreach ([$event->oldParent, $event->newParent] as $epic) {
            if (null !== $epic) {
                $this->epicChanged($epic);
            }
        }
    }

    #[AsEventListener]
    public function onCardMoved(CardMoved $event): void
    {
        $card = $event->card;
        $from = $event->move->fromColumn;
        // The epic face counts the finished children, and its deck shows the Backlog ones in rank order.
        if ($from->terminal === $card->column->terminal && !$from->backlog && !$card->column->backlog) {
            return;
        }

        // The move itself does not read the parent under the lock.
        $this->cards->refreshTypeAndParent($card);
        if (null !== $card->parent) {
            $this->epicChanged($card->parent);
        }
    }

    private function epicChanged(Card $epic): void
    {
        $this->events->dispatch(new CardChanged(
            $epic->project->id ?? throw new \LogicException('Project has no id.'),
            $epic->id ?? throw new \LogicException('Card has no id.'),
            CardChanged::UPDATED,
            false,
        ));
    }
}
