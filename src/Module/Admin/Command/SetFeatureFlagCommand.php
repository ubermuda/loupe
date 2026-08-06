<?php

declare(strict_types=1);

namespace App\Module\Admin\Command;

final readonly class SetFeatureFlagCommand
{
    public function __construct(
        public string $name,
        public bool $enabled,
    ) {
    }
}
