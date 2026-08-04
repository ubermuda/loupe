<?php

declare(strict_types=1);

namespace App\Module\Account\Form;

use Symfony\Component\Validator\Constraints as Assert;

class RegistrationRequest
{
    public function __construct(
        #[Assert\Email]
        #[Assert\NotBlank]
        public ?string $email = null,

        #[Assert\Length(max: 150)]
        #[Assert\NotBlank]
        public ?string $fullName = null,

        #[Assert\Length(min: 8)]
        #[Assert\NotBlank]
        public ?string $plainPassword = null,

        #[Assert\IsTrue(message: 'account.registration.validator.agree_terms')]
        public bool $agreeTerms = false,
    ) {
    }
}
