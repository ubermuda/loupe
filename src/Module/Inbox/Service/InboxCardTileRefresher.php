<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Board\Event\CardChanged;
use App\Module\Inbox\Entity\InboxItem;
use Psr\EventDispatcher\EventDispatcherInterface;

/** An item that opens or closes can change the state of the cards it names, so their tiles redraw. */
final readonly class InboxCardTileRefresher
{
    public function __construct(
        private EventDispatcherInterface $events,
    ) {
    }

    /** Call it after the commit that opened or closed the items. */
    public function refresh(InboxItem ...$items): void
    {
        foreach ($items as $item) {
            foreach ($item->cards as $link) {
                $this->events->dispatch(new CardChanged(
                    $item->project->id ?? throw new \LogicException('Project has no id.'),
                    $link->card->id ?? throw new \LogicException('Card has no id.'),
                    CardChanged::UPDATED,
                    false,
                ));
            }
        }
    }
}
