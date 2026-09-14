<?php

declare(strict_types=1);

namespace App\Module\Inbox\Form;

use Symfony\Component\Validator\Constraints as Assert;

class AnswerInboxItemRequest
{
    public const int MAX_ANSWER_LENGTH = 10000;

    public function __construct(
        /** Comma-separated option indexes, which the answer controller copies from the option controls. */
        public ?string $selectedOptions = null,

        #[Assert\Length(max: self::MAX_ANSWER_LENGTH)]
        public ?string $answerText = null,
    ) {
    }
}
