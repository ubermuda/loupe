<?php

declare(strict_types=1);

namespace App\Command;

final readonly class CheckDatabaseHealthView
{
    public function __construct(
        public bool $healthy,
    ) {
    }
}
