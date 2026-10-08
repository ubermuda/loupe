<?php

declare(strict_types=1);

namespace App\Module\DesignSystem\Token;

final readonly class TokenGroup
{
    /** @param list<Token> $tokens */
    public function __construct(
        public string $name,
        public array $tokens,
    ) {
    }
}
