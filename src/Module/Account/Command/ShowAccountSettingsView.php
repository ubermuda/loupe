<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\User;

final readonly class ShowAccountSettingsView
{
    /**
     * @param list<DataExport> $exports
     * @param list<ApiToken>   $apiTokens
     */
    public function __construct(
        public User $user,
        public array $exports,
        public array $apiTokens,
    ) {
    }
}
