<?php

declare(strict_types=1);

namespace App\Module\Review\Form;

use Symfony\Component\Validator\Constraints as Assert;

class ReplyRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public ?string $body = null,
    ) {
    }
}
