<?php

declare(strict_types=1);

namespace App\Module\Board\Messenger;

use App\Module\Board\Command\ReturnAbandonedCardCommand;
use App\Module\Board\Command\ReturnAbandonedCardHandler;
use App\Module\Board\Service\BoardAvailability;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class MoveAbandonedCardHandler
{
    public function __construct(
        private ReturnAbandonedCardHandler $returnAbandonedCard,
        private BoardAvailability $board,
    ) {
    }

    public function __invoke(MoveAbandonedCard $message): void
    {
        if (!$this->board->isEnabled()) {
            return;
        }

        ($this->returnAbandonedCard)(new ReturnAbandonedCardCommand($message->cardId, $message->token));
    }
}
