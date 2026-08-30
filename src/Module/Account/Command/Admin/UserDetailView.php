<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Admin;

use App\Module\Account\Admin\AdminUserPanel;
use App\Module\Account\Entity\ConnectedAccount;
use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\User;

/**
 * The context an admin needs to act on one account. Everything here is owned by
 * the Account module. Another module reaches this page only through
 * AdminUserPanelInterface, which hands over a template and a context rather
 * than a type Account would have to import.
 */
final readonly class UserDetailView
{
    /**
     * @param list<ConnectedAccount> $connectedAccounts
     * @param list<DataExport>       $dataExports
     * @param list<AdminUserPanel>   $panels
     */
    public function __construct(
        public User $user,
        public array $connectedAccounts,
        public int $apiTokenCount,
        public array $dataExports,
        public array $panels = [],
    ) {
    }
}
