<?php

declare(strict_types=1);

namespace App\Module\Inbox\Form;

use Symfony\Component\Validator\Constraints as Assert;

final class SubmitInboxPullRequestReviewRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public ?string $verdict = null,

        #[Assert\NotBlank]
        public ?string $expectedUrl = null,
        public ?string $note = null,
    ) {
    }
}
