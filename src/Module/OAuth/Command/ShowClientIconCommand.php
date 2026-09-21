<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

final readonly class ShowClientIconCommand
{
    public function __construct(
        public string $clientIdentifier,
    ) {
    }
}
