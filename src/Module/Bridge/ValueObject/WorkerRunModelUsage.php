<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** The tokens one model spent in one run, as the bridge reports them. */
final readonly class WorkerRunModelUsage
{
    public function __construct(
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public int $cacheReadTokens,
        public int $cacheWriteTokens,
        /** A decimal string with six places, or null when the bridge knows no price. */
        public ?string $costUsd,
    ) {
    }
}
