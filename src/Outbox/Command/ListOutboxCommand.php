<?php

declare(strict_types=1);

namespace App\Outbox\Command;

final readonly class ListOutboxCommand
{
    public function __construct(
        public int $page,
        public string $sort,
        public string $dir,
        public string $requestedProjectId,
    ) {
    }
}
