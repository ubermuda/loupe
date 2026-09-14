<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Service\InboxLinkedPage;
use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

final readonly class ShowLinkedInboxItemsCommand
{
    public function __construct(
        /** The project the card or document belongs to. An item of any other project never shows. */
        public Project $project,
        public InboxLinkedPage $page,
        public Uuid $targetId,
    ) {
    }
}
