<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Board\Service\CardMover;

/** Moves one card from the Backlog to the end of a column the board draws. */
final readonly class MoveBacklogCardHandler
{
    public const string NOT_IN_BACKLOG = 'board.backlog.error.not_in_backlog';
    public const string TARGET_IS_BACKLOG = 'board.backlog.error.target_is_backlog';

    public function __construct(
        private UpdateCardHandler $updateCard,
    ) {
    }

    public function __invoke(MoveBacklogCardCommand $command): Card
    {
        $card = $command->card;
        if (!$card->column->backlog) {
            throw new DomainErrors(['card' => self::NOT_IN_BACKLOG]);
        }
        if ($command->column->backlog) {
            throw new DomainErrors(['column' => self::TARGET_IS_BACKLOG]);
        }

        return ($this->updateCard)(new UpdateCardCommand(
            card: $card,
            actor: $command->actor,
            column: $command->column,
            position: CardMover::END_OF_COLUMN,
            expectedColumn: $card->column,
        ))->card;
    }
}
