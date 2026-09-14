<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Account\Entity\User;

/** The account inbox page: the open asks across every project the owner owns. */
final readonly class AccountInboxDetailView
{
    /**
     * @param list<AccountInboxProjectGroup> $groups by project name
     */
    public function __construct(
        public User $owner,
        public array $groups,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->groups;
    }
}
