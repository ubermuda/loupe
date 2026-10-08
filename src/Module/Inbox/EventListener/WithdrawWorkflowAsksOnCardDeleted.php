<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Board\Event\CardChanged;
use App\Module\Inbox\Service\InboxRuleAsks;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** The rule states go with a deleted card, so the engine cannot withdraw the question. */
#[AsEventListener]
final readonly class WithdrawWorkflowAsksOnCardDeleted
{
    public function __construct(
        private InboxRuleAsks $asks,
    ) {
    }

    public function __invoke(CardChanged $event): void
    {
        if (CardChanged::DELETED !== $event->change) {
            return;
        }

        $this->asks->withdrawForCard($event->cardId, InboxRuleAsks::CARD_DELETED);
    }
}
