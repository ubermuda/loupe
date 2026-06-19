<?php

declare(strict_types=1);

namespace App\Module\Account\Form;

use App\Module\Account\Validator\NotReservedUsername;
use Symfony\Component\Validator\Constraints as Assert;

class RegistrationRequest
{
    public function __construct(
        #[Assert\Length(max: 150)]
        #[Assert\NotBlank]
        public ?string $fullName = null,

        #[Assert\Length(min: 3, max: 30)]
        #[Assert\NotBlank]
        #[Assert\Regex(
            pattern: '/^[a-z][a-z0-9_-]*$/',
            message: 'account.registration.validator.username_format',
        )]
        #[NotReservedUsername]
        public ?string $username = null,

        #[Assert\Email]
        #[Assert\NotBlank]
        public ?string $email = null,

        #[Assert\Length(min: 8)]
        #[Assert\NotBlank]
        public ?string $plainPassword = null,

        #[Assert\IsTrue(message: 'account.registration.validator.agree_terms')]
        public bool $agreeTerms = false,
    ) {
    }
}
