<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Module\Account\Repository\UserRepository;

/**
 * The install wizard is open only while the users table is empty; creating the
 * first (admin) account closes it permanently.
 */
final readonly class InstallationState
{
    public function __construct(
        private UserRepository $users,
    ) {
    }

    public function isOpen(): bool
    {
        return 0 === $this->users->count([]);
    }
}
