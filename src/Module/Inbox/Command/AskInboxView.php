<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxItem;

/** The ask the items went to, and the items this call created, in the order given. */
final readonly class AskInboxView
{
    /** @param list<InboxItem> $items */
    public function __construct(
        public InboxAsk $ask,
        public array $items,
        public bool $extended,
    ) {
    }
}
