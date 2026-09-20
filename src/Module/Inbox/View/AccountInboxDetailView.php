<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Bridge\View\BridgeStatus;
use App\Module\Inbox\Entity\InboxAsk;

/** The account inbox page: what waits on the owner across every project they own. */
final readonly class AccountInboxDetailView
{
    /**
     * @param list<AccountInboxProjectGroup> $groups         by project name
     * @param array<string, BridgeStatus>    $bridgeStatuses the bridges of the open asks, by bridge id
     */
    public function __construct(
        public array $groups,
        public array $bridgeStatuses,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->groups;
    }

    /** Null for an ask no bridge started, which runs in an interactive session. */
    public function bridgeStatus(InboxAsk $ask): ?BridgeStatus
    {
        if (null !== $ask->closedAt || null === $ask->bridgeId) {
            return null;
        }

        return $this->bridgeStatuses[$ask->bridgeId->toRfc4122()]
            ?? throw new \LogicException('The account inbox handler reads the bridge of every open ask on the page.');
    }
}
