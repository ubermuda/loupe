<?php

declare(strict_types=1);

namespace App\Module\Review\Form;

use App\Module\Review\Entity\DecisionAnswer;
use App\Module\Review\Entity\DecisionSelection;
use Symfony\Component\Validator\Constraints as Assert;

final class SaveDecisionAnswerRequest
{
    /**
     * @param list<int> $optionIndexes
     */
    public function __construct(
        #[Assert\Length(max: DecisionSelection::MAX_DECISION_ID_LENGTH)]
        #[Assert\NotBlank]
        public ?string $decisionId = null,

        #[Assert\NotNull]
        #[Assert\Positive]
        public ?int $versionNumber = null,

        #[Assert\All([new Assert\NotNull(), new Assert\PositiveOrZero()])]
        public array $optionIndexes = [],

        #[Assert\Length(max: DecisionAnswer::MAX_NOTE_LENGTH, maxMessage: 'review.form.save_decision_answer_form.note.too_long')]
        public ?string $note = null,
        public bool $clear = false,
    ) {
    }
}
