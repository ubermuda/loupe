<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxItem;

/** One item with every ask that holds it. */
final readonly class InboxItemDetailView
{
    /** @param list<InboxAsk> $asks */
    public function __construct(
        public InboxItem $item,
        public array $asks,
    ) {
    }
}
