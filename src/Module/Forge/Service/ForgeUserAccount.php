<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

/** What a forge module knows of one Loupe user's account on that forge. */
final readonly class ForgeUserAccount
{
    public function __construct(
        public ForgeUserConnectionState $state,
        public ?string $forgeUserId = null,
    ) {
    }
}
