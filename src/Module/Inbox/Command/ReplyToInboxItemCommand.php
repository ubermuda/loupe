<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Account\Entity\User;
use App\Module\Inbox\Entity\InboxItem;

final readonly class ReplyToInboxItemCommand
{
    public function __construct(
        public InboxItem $item,
        public User $author,
        public string $body,
        public string $submissionId,
    ) {
    }
}
