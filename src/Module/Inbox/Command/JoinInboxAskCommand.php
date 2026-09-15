<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxItem;
use Symfony\Component\Uid\Uuid;

final readonly class JoinInboxAskCommand
{
    public function __construct(
        public InboxItem $item,
        public Uuid $sessionId,
        public ?Uuid $bridgeId = null,
    ) {
    }
}
