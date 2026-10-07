<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

/** One check of a discovery report, as the worker states it. */
final readonly class ReportFinding
{
    public function __construct(
        public string $check,
        /** Either ready or gap. */
        public string $status,
        public string $evidence,
    ) {
    }
}
