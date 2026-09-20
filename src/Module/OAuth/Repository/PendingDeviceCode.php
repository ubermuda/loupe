<?php

declare(strict_types=1);

namespace App\Module\OAuth\Repository;

/** A device code that waits for a person to approve or deny it. */
final readonly class PendingDeviceCode
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $identifier,
        public string $userCode,
        public string $clientId,
        public string $clientName,
        public array $scopes,
    ) {
    }
}
