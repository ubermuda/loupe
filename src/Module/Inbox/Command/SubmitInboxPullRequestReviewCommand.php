<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Account\Entity\User;
use App\Module\Inbox\Entity\InboxItem;

final readonly class SubmitInboxPullRequestReviewCommand
{
    public function __construct(
        public InboxItem $item,
        public User $reviewer,
        public string $verdict,
        public string $expectedUrl,
        public ?string $note = null,
    ) {
    }
}
