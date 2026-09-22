<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\OAuth\Scope\ApiScope;

final readonly class DeviceConsentView
{
    /** @param string $deviceCodeId the secret the device polls with, never rendered */
    public function __construct(
        public string $deviceCodeId,
        public string $userCode,
        public string $displayUserCode,
        public string $clientId,
        public string $clientName,
        /** @var non-empty-list<ApiScope> */
        public array $scopes,
        public bool $allProjects,
    ) {
    }
}
