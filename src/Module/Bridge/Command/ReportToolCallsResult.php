<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

/** What a tool call report did. $stored counts the rows it inserted. */
final readonly class ReportToolCallsResult
{
    public function __construct(
        public bool $projectFound,
        public bool $runFound,
        public int $stored,
    ) {
    }
}
