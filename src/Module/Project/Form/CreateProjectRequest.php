<?php

declare(strict_types=1);

namespace App\Module\Project\Form;

use Symfony\Component\Validator\Constraints as Assert;

class CreateProjectRequest
{
    public function __construct(
        #[Assert\Length(max: 100, normalizer: 'trim')]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $name = null,

        #[Assert\Length(max: 255, normalizer: 'trim')]
        public ?string $domain = null,
    ) {
    }
}
