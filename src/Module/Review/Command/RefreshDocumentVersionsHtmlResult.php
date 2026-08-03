<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

final readonly class RefreshDocumentVersionsHtmlResult
{
    public function __construct(
        public int $total = 0,
        public int $changed = 0,
        /** Versions carrying anchored comments whose plain text the re-render would move. */
        public int $atRisk = 0,
        /** True when $atRisk stopped the run and nothing was written. */
        public bool $refused = false,
    ) {
    }
}
