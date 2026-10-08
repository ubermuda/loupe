<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Bridge\Event\CardHeld;
use App\Module\Inbox\Service\InboxRuleAsks;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** The engine skips a held card, so it cannot withdraw the question of a rule that stopped holding. */
#[AsEventListener]
final readonly class WithdrawWorkflowAsksOnCardHeld
{
    public function __construct(
        private InboxRuleAsks $asks,
    ) {
    }

    public function __invoke(CardHeld $event): void
    {
        $this->asks->withdrawForCard($event->cardId, InboxRuleAsks::CARD_HELD);
    }
}
