<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxReply;

final readonly class InboxReplyThreads
{
    /** @var array<string, list<InboxReply>> */
    private array $byItem;

    /** @param list<InboxReply> $replies */
    public function __construct(array $replies)
    {
        $byItem = [];
        foreach ($replies as $reply) {
            $byItem[(string) $reply->item->id][] = $reply;
        }
        $this->byItem = $byItem;
    }

    /** @return list<InboxReply> */
    public function forItem(InboxItem $item): array
    {
        return $this->byItem[(string) $item->id] ?? [];
    }
}
