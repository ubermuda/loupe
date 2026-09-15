<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxItem;
use Symfony\Component\Uid\Uuid;

final readonly class ShowInboxItemCommand
{
    public function __construct(
        public InboxItem $item,
        public ?Uuid $readerSessionId = null,
    ) {
    }
}
