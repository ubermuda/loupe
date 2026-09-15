<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Project\Entity\Project;

/** Whether an ask is closed and its session read every item. Null fields name what was not found. */
final readonly class CheckInboxAskView
{
    public function __construct(
        public ?Project $project,
        public ?InboxAsk $ask,
        public bool $allRead = false,
    ) {
    }
}
