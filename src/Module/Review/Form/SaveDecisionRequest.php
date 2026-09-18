<?php

declare(strict_types=1);

namespace App\Module\Review\Form;

use Symfony\Component\Validator\Constraints as Assert;

final class SaveDecisionRequest
{
    /**
     * @param list<int> $optionIndexes
     * @param list<int> $expectedOptionIndexes
     */
    public function __construct(
        #[Assert\Length(max: 64)]
        #[Assert\NotBlank]
        public ?string $decisionId = null,

        #[Assert\NotNull]
        #[Assert\Positive]
        public ?int $versionNumber = null,

        #[Assert\All([new Assert\NotNull(), new Assert\PositiveOrZero()])]
        public array $optionIndexes = [],

        #[Assert\All([new Assert\NotNull(), new Assert\PositiveOrZero()])]
        public array $expectedOptionIndexes = [],
    ) {
    }
}
