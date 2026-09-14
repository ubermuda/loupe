<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Project\Entity\Project;

/** One project on the account inbox page, with its open asks and the open items outside them. */
final readonly class AccountInboxProjectGroup
{
    /**
     * @param list<InboxAsk>  $openAsks   oldest first
     * @param list<InboxItem> $looseItems open items that no open ask holds, by number
     */
    public function __construct(
        public Project $project,
        public array $openAsks,
        public array $looseItems,
        public int $openItemCount,
    ) {
    }
}
