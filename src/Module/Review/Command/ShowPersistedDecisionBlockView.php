<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

final readonly class ShowPersistedDecisionBlockView
{
    public function __construct(
        public ?string $blockHtml,
    ) {
    }
}
