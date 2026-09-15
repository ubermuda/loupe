<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Repository\InboxAskRepository;

final readonly class ShowInboxItemHandler
{
    public function __construct(
        private InboxAskRepository $inboxAsks,
    ) {
    }

    public function __invoke(ShowInboxItemCommand $command): InboxItemDetailView
    {
        return new InboxItemDetailView($command->item, $this->inboxAsks->findHolding($command->item));
    }
}
