<?php

declare(strict_types=1);

namespace App\Module\Project\Form;

use Symfony\Component\Validator\Constraints\NotBlank;

final class DeleteProjectRequest
{
    public function __construct(
        #[NotBlank]
        public ?string $confirmName = null,
    ) {
    }
}
