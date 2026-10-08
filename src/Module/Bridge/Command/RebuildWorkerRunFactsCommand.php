<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

/** Rewrites the fact row of every run, a batch of runs at a time. */
final readonly class RebuildWorkerRunFactsCommand
{
    public function __construct(
        /** @var positive-int */
        public int $batchSize = 500,
    ) {
    }
}
