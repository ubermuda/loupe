<?php

declare(strict_types=1);

namespace App\Module\Account\Form;

use Symfony\Component\Validator\Constraints as Assert;

class InstallAdminRequest
{
    public function __construct(
        #[Assert\Length(max: 150)]
        #[Assert\NotBlank]
        public ?string $fullName = null,

        // No NotReservedUsername here: this wizard creates the application's
        // actual admin account, so a username like "admin" must be allowed —
        // the reserved-username guard exists to stop self-service registration
        // from impersonating that role, which does not apply to this flow.
        #[Assert\Length(min: 3, max: 30)]
        #[Assert\NotBlank]
        #[Assert\Regex(
            pattern: '/^[a-z][a-z0-9_-]*$/',
            message: 'account.registration.validator.username_format',
        )]
        public ?string $username = null,

        #[Assert\Email]
        #[Assert\NotBlank]
        public ?string $email = null,

        #[Assert\Length(min: 8)]
        #[Assert\NotBlank]
        public ?string $plainPassword = null,
    ) {
    }
}
