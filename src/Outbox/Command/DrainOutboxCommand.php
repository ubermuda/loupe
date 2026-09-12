<?php

declare(strict_types=1);

namespace App\Outbox\Command;

final readonly class DrainOutboxCommand
{
    public const int DEFAULT_LIMIT = 50;

    public function __construct(
        public int $limit = self::DEFAULT_LIMIT,
    ) {
    }
}
