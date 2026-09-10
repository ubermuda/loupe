<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Account\Entity\User;
use App\Module\Review\Entity\Document;

final readonly class ShowSectionSummaryCommand
{
    /**
     * @param int|null $displayedVersionNumber the version the page was rendered from,
     *                                         null to fall back to the latest
     */
    public function __construct(
        public Document $document,
        public User $reader,
        public ?int $displayedVersionNumber = null,
    ) {
    }
}
