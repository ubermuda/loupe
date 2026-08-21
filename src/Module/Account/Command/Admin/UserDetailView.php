<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Admin;

use App\Module\Account\Entity\ConnectedAccount;
use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\User;

/**
 * The context an admin needs to act on one account. Everything here is owned by
 * the Account module: project and billing figures would each cross a module
 * boundary, so they are absent rather than reached for.
 */
final readonly class UserDetailView
{
    /**
     * @param list<ConnectedAccount> $connectedAccounts
     * @param list<DataExport>       $dataExports
     */
    public function __construct(
        public User $user,
        public array $connectedAccounts,
        public int $apiTokenCount,
        public array $dataExports,
    ) {
    }
}
