<?php

declare(strict_types=1);

namespace App\Module\DesignSystem\Token;

final readonly class Token
{
    public function __construct(
        public string $name,
        public string $value,
        public string $use,
    ) {
    }
}
