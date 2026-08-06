<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

final readonly class PrepareHarnessView
{
    public function __construct(
        public string $rawToken,
    ) {
    }
}
