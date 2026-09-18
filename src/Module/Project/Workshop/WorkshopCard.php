<?php

declare(strict_types=1);

namespace App\Module\Project\Workshop;

final readonly class WorkshopCard
{
    public function __construct(
        public int $number,
        public string $title,
        public string $url,
        public string $column,
    ) {
    }
}
