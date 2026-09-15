<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxItem;

final readonly class MarkInboxItemDoneCommand
{
    public function __construct(
        public InboxItem $item,
    ) {
    }
}
