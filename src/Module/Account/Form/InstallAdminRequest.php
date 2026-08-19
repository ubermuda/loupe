<?php

declare(strict_types=1);

namespace App\Module\Account\Form;

use App\Module\Account\Entity\User;
use Symfony\Component\Validator\Constraints as Assert;

class InstallAdminRequest
{
    public function __construct(
        #[Assert\Email]
        #[Assert\NotBlank]
        public ?string $email = null,

        #[Assert\Length(max: User::MAX_FULL_NAME_LENGTH)]
        #[Assert\NotBlank]
        public ?string $fullName = null,

        #[Assert\Length(min: 8)]
        #[Assert\NotBlank]
        public ?string $plainPassword = null,
    ) {
    }
}
