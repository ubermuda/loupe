<?php

namespace App\Module\Account\Form;

use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

class PasswordResetRequest
{
    public function __construct(
        #[Email]
        #[NotBlank]
        public ?string $email = null,
    ) {
    }
}
