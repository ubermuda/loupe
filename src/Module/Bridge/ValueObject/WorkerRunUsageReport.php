<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** The usage of one run. No models means the run spent nothing. */
final readonly class WorkerRunUsageReport
{
    /** @param list<WorkerRunModelUsage> $models */
    public function __construct(
        public WorkerRunUsageSource $source,
        public array $models,
    ) {
    }

    /** Reported counts replace estimated ones, and nothing replaces reported counts. */
    public function replaces(?WorkerRunUsageSource $current): bool
    {
        return null === $current
            || (WorkerRunUsageSource::Estimated === $current && WorkerRunUsageSource::Reported === $this->source);
    }
}
