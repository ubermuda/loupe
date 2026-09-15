<?php

declare(strict_types=1);

namespace App\Module\Inbox\Form;

use Symfony\Component\Validator\Constraints as Assert;

class DeclineInboxItemRequest
{
    public function __construct(
        #[Assert\Length(max: AnswerInboxItemRequest::MAX_ANSWER_LENGTH)]
        public ?string $closeNote = null,
    ) {
    }
}
