<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Document;

final readonly class ShowDecisionSummaryCommand
{
    /**
     * @param int|null $displayedVersionNumber the version the page was rendered from,
     *                                         null to fall back to the latest
     */
    public function __construct(
        public Document $document,
        public ?int $displayedVersionNumber = null,
    ) {
    }
}
