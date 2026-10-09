<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

final readonly class VerdictActionOption
{
    public function __construct(
        public string $code,
        public string $label,
    ) {
    }
}
