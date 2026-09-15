<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

/** The account inbox page: what waits on the owner across every project they own. */
final readonly class AccountInboxDetailView
{
    /**
     * @param list<AccountInboxProjectGroup> $groups by project name
     */
    public function __construct(
        public array $groups,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->groups;
    }
}
