<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;

/**
 * Moves a Backlog card next to the neighbour a drag on the Backlog page names.
 * The neighbour is resolved against the whole Backlog, so with a filter on,
 * the card lands directly above the visible row below it.
 */
final readonly class RankBacklogCardHandler
{
    public const string NOT_IN_BACKLOG = 'board.backlog.error.not_in_backlog';

    public function __construct(
        private UpdateCardHandler $updateCard,
    ) {
    }

    public function __invoke(RankBacklogCardCommand $command): Card
    {
        $card = $command->card;
        if (!$card->column->backlog) {
            throw new DomainErrors(['card' => self::NOT_IN_BACKLOG]);
        }

        return ($this->updateCard)(new UpdateCardCommand(
            card: $card,
            actor: $command->actor,
            column: $card->column,
            beforeCardId: $command->beforeCardId,
            afterCardId: $command->afterCardId,
        ))->card;
    }
}
