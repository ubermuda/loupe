<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

/** Every filter is optional, and null means "any". */
final readonly class ListInboxItemsCommand
{
    public function __construct(
        public Project $project,
        public ?InboxItemState $state = null,
        public ?Uuid $askId = null,
        public ?Uuid $sessionId = null,
        public ?Uuid $cardId = null,
        public ?Uuid $documentId = null,
        public int $page = 1,
        public int $perPage = ListInboxItemsHandler::DEFAULT_PER_PAGE,
        /** The session that reads, which is never a filter. */
        public ?Uuid $readerSessionId = null,
    ) {
    }
}
