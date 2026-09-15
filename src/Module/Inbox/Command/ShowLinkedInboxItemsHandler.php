<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\View\LinkedInboxItemsView;

final readonly class ShowLinkedInboxItemsHandler
{
    public function __construct(
        private InboxItemRepository $inboxItems,
    ) {
    }

    public function __invoke(ShowLinkedInboxItemsCommand $command): LinkedInboxItemsView
    {
        $linked = $this->inboxItems->findLinkedTo($command->project, $command->page, $command->targetId);

        $asksByItem = [];
        foreach ($linked['memberships'] as $membership) {
            $asksByItem[(string) $membership->item->id][] = $membership->ask;
        }

        return new LinkedInboxItemsView(
            project: $command->project,
            page: $command->page,
            targetId: $command->targetId,
            items: $linked['items'],
            asksByItem: $asksByItem,
            versionNumber: $command->versionNumber,
        );
    }
}
