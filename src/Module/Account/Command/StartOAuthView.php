<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

final readonly class StartOAuthView
{
    public function __construct(
        public string $authorizationUrl,
    ) {
    }
}
