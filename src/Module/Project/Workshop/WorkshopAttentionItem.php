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
        /** Who raised the item, such as "agent". The row shows it as an icon. */
        public string $origin,
    ) {
    }
}
