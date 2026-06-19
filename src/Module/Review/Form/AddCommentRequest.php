<?php

declare(strict_types=1);

namespace App\Module\Review\Form;

use Symfony\Component\Validator\Constraints as Assert;

class AddCommentRequest
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\PositiveOrZero]
        public ?int $start = null,

        #[Assert\NotNull]
        #[Assert\Positive]
        public ?int $length = null,

        #[Assert\NotBlank]
        public ?string $body = null,
    ) {
    }
}
