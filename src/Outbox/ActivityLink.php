<?php

declare(strict_types=1);

namespace App\Outbox;

final readonly class ActivityLink
{
    public function __construct(
        public string $url,
        public string $label,
    ) {
    }
}
