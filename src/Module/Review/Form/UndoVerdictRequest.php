<?php

declare(strict_types=1);

namespace App\Module\Review\Form;

use Symfony\Component\Validator\Constraints as Assert;

final class UndoVerdictRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public ?string $reviewId = null,
    ) {
    }
}
