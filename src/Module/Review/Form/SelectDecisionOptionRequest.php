<?php

declare(strict_types=1);

namespace App\Module\Review\Form;

use App\Module\Review\Entity\DecisionSelection;
use Symfony\Component\Validator\Constraints as Assert;

class SelectDecisionOptionRequest
{
    public function __construct(
        // Both are filled client-side from the clicked radio, so neither is ever
        // typed. The constraints guard a hand-crafted POST: decisionId backs a
        // VARCHAR column and would otherwise reach the driver, and a negative
        // index would be looked up rather than rejected.
        #[Assert\Length(max: DecisionSelection::MAX_DECISION_ID_LENGTH)]
        #[Assert\NotBlank]
        public ?string $decisionId = null,

        #[Assert\NotNull]
        #[Assert\PositiveOrZero]
        public ?int $optionIndex = null,
        /**
         * Whether the reviewer wants the option chosen, read from the control
         * they clicked. Sent rather than inferred, so a second tab holding an
         * older view of the block cannot undo what the first one did, and a
         * repeated POST lands on the same answer. False by default, because an
         * unticked checkbox submits nothing and the form reads that as false.
         */
        public bool $chosen = false,

        /**
         * The version the reviewer was looking at when they clicked. An index
         * only means anything against the option list it was rendered from, so
         * the handler refuses it once a revision has moved on.
         */
        #[Assert\NotNull]
        #[Assert\Positive]
        public ?int $versionNumber = null,
    ) {
    }
}
