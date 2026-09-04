<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Account\Entity\User;
use App\Module\Review\Entity\Document;

final readonly class SetSectionApprovalCommand
{
    public function __construct(
        public Document $document,
        public User $reviewer,
        public string $headingId,
        public bool $approved,
        /** The version whose sections the reviewer actually read. */
        public int $displayedVersionNumber,
    ) {
    }
}
