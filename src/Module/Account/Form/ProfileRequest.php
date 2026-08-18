<?php

declare(strict_types=1);

namespace App\Module\Account\Form;

use Symfony\Component\Validator\Constraints as Assert;

class ProfileRequest
{
    public function __construct(
        #[Assert\Length(max: 150, normalizer: 'trim')]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $fullName = null,
    ) {
    }
}
