<?php

declare(strict_types=1);

namespace App\Audit\Command\Admin;

final readonly class ListAuditLogView
{
    /**
     * @param list<AuditLogRow>     $rows
     * @param list<int|null>        $pageList
     * @param array<string, string> $filters  the active filters, empty values stripped
     * @param list<string>          $channels every channel the screen offers as a filter
     */
    public function __construct(
        public array $rows,
        public int $total,
        public int $totalPages,
        public array $pageList,
        public array $filters,
        public array $channels,
        public ?int $clampedPage,
    ) {
    }
}
