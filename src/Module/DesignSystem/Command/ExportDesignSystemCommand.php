<?php

declare(strict_types=1);

namespace App\Module\DesignSystem\Command;

final readonly class ExportDesignSystemCommand
{
    public function __construct(
        public string $directory,
    ) {
    }
}
