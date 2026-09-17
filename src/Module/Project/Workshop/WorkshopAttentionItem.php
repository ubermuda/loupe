<?php

declare(strict_types=1);

namespace App\Module\Project\Workshop;

final readonly class WorkshopAttentionItem
{
    public function __construct(
        public string $title,
        public string $url,
        public string $kind,
        public bool $blocking,
    ) {
    }
}
