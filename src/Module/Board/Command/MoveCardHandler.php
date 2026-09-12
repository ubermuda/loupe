<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Service\CardMover;

/**
 * Moves a card inside the board, for the drag-and-drop endpoint and its payload.
 *
 * UpdateCardHandler owns the move, its transaction, its lock and its records,
 * so one handler moves a card and this one adapts the request to it. The
 * delegation goes this way round only: UpdateCardHandler calling back would
 * nest the transaction, and the inner commit would then be a counter decrement
 * rather than a real commit.
 */
final readonly class MoveCardHandler
{
    public function __construct(
        private UpdateCardHandler $updateCard,
    ) {
    }

    public function __invoke(MoveCardCommand $command): Card
    {
        // A null rank means the end of the target group here, and "leave the
        // rank alone" in an update, so it becomes an explicit rank.
        return ($this->updateCard)(new UpdateCardCommand(
            card: $command->card,
            priority: $command->priority,
            status: $command->status,
            position: $command->position ?? CardMover::END_OF_GROUP,
        ));
    }
}
