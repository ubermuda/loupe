<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

final readonly class RefreshDocumentVersionsHtmlResult
{
    public function __construct(
        public int $total = 0,
        public int $changed = 0,
        /** Anchored comments whose quote the re-rendered text no longer contains. */
        public int $atRisk = 0,
        /** True when $atRisk stopped the run and nothing was written. */
        public bool $refused = false,
    ) {
    }
}
