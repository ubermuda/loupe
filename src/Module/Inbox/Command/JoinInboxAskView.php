<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxItem;

/** The ask that now holds the item. $added is false when it already held it. */
final readonly class JoinInboxAskView
{
    public function __construct(
        public InboxAsk $ask,
        public InboxItem $item,
        public bool $extended,
        public bool $added,
    ) {
    }
}
