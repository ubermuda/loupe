<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxItem;

/** What the item partial asks of the page that includes it. */
interface InboxItemsView
{
    /** Whether the item's forms render here, in $ask, or on their own when $ask is null. */
    public function isHome(InboxItem $item, ?InboxAsk $ask = null): bool;

    /** Whether the owner can respond to the item, or change the response already given. */
    public function acceptsResponse(InboxItem $item): bool;

    /**
     * The query string every form of the page posts with, so a response returns to this page.
     *
     * @return array<string, int|string>
     */
    public function actionQuery(): array;
}
