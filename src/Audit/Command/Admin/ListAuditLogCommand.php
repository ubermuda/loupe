<?php

declare(strict_types=1);

namespace App\Audit\Command\Admin;

final readonly class ListAuditLogCommand
{
    public function __construct(
        public int $page,
        public string $dir,
        public ?string $actor = null,
        public ?string $operation = null,
        public ?string $channel = null,
        public ?string $from = null,
        public ?string $to = null,
    ) {
    }
}
