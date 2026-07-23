<?php

namespace App\Module\Account\Form;

use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class ChangePasswordRequest
{
    public function __construct(
        #[Length(min: 8)]
        #[NotBlank]
        public ?string $plainPassword = null,
    ) {
    }
}
