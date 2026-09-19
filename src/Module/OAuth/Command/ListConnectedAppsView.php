<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

final readonly class ListConnectedAppsView
{
    /** @param list<ConnectedApp> $apps */
    public function __construct(
        public array $apps,
    ) {
    }
}
