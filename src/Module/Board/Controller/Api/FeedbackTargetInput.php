<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Either an open card of the project, or a card to create from the note. */
final class FeedbackTargetInput
{
    public function __construct(
        #[Assert\Uuid]
        public ?string $cardId = null,

        #[Assert\Valid]
        public ?NewCardInput $newCard = null,
    ) {
    }

    #[Assert\Callback]
    public function validateOneTarget(ExecutionContextInterface $context): void
    {
        if ((null === $this->cardId) === (null === $this->newCard)) {
            $context->buildViolation('Name either cardId or newCard.')->addViolation();
        }
    }
}
