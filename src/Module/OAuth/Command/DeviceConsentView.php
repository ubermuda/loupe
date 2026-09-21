<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\Account\Entity\ApiTokenScope;

final readonly class DeviceConsentView
{
    /** @param string $deviceCodeId the secret the device polls with, never rendered */
    public function __construct(
        public string $deviceCodeId,
        public string $userCode,
        public string $displayUserCode,
        public string $clientId,
        public string $clientName,
        /** @var non-empty-list<ApiTokenScope> */
        public array $scopes,
        public bool $allProjects,
    ) {
    }
}
