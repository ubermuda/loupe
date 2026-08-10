<?php

declare(strict_types=1);

namespace App\Service;

final readonly class UpdateStatus
{
    public function __construct(
        public string $latestVersion,
        public bool $isOutdated,
    ) {
    }
}
