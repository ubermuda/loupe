<?php

declare(strict_types=1);

namespace App\Module\Review\ValueObject;

final readonly class Anchor
{
    public function __construct(
        public string $quote,
        public string $prefix,
        public string $suffix,
        public int $offsetHint,
    ) {
    }
}
