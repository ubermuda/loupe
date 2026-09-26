<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

/**
 * What a session usage report did. $runs is null when the owner has no project
 * by that handle. $updated counts the runs whose usage changed, and it is zero
 * when the count of runs differs from the count of processes.
 */
final readonly class ReportSessionUsageResult
{
    public function __construct(
        public ?int $runs,
        public int $processes,
        public int $updated,
    ) {
    }
}
