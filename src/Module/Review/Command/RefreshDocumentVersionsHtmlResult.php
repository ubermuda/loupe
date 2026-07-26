<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

final readonly class RefreshDocumentVersionsHtmlResult
{
    public function __construct(
        public int $total = 0,
        public int $changed = 0,
    ) {
    }
}
