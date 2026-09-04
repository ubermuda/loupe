<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Document;

final readonly class SelectDecisionOptionCommand
{
    public function __construct(
        public Document $document,
        public string $decisionId,
        public int $optionIndex,
        /** The version whose option list the reviewer actually clicked on. */
        public int $displayedVersionNumber,
        /**
         * Whether the option is to be chosen. Only a multi-choice block reads
         * it, because a radio is always chosen by the click that posts it.
         */
        public bool $chosen = true,
    ) {
    }
}
