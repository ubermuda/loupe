<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Project\Entity\Project;

/** One project on the account inbox page, with its open asks. */
final readonly class AccountInboxProjectGroup
{
    /**
     * @param list<InboxAsk> $openAsks oldest first
     */
    public function __construct(
        public Project $project,
        public array $openAsks,
        public int $openItemCount,
    ) {
    }
}
