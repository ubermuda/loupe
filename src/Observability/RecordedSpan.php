<?php

declare(strict_types=1);

namespace App\Observability;

final readonly class RecordedSpan
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $op,
        public float $start,
        public float $end,
        public ?string $description = null,
        public array $data = [],
    ) {
    }
}
