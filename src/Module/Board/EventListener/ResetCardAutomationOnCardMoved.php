<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Event\CardMoved;
use App\Module\Board\Repository\CardAutomationRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A person who moves a card takes it back from the automation, so its fix
 * rounds start again. It runs inside UpdateCardHandler's transaction, and a
 * throw refuses the move.
 */
#[AsEventListener]
final readonly class ResetCardAutomationOnCardMoved
{
    public function __construct(
        private CardAutomationRepository $cardAutomations,
    ) {
    }

    public function __invoke(CardMoved $event): void
    {
        if (CardReporter::Human === $event->actor) {
            $this->cardAutomations->reset($event->card);
        }
    }
}
