<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

final readonly class PrepareHarnessCommand
{
    public function __construct(
        public string $email,
        public bool $keepComments,
    ) {
    }
}
