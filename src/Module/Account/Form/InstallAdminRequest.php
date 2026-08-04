<?php

declare(strict_types=1);

namespace App\Module\Account\Form;

use Symfony\Component\Validator\Constraints as Assert;

class InstallAdminRequest
{
    public function __construct(
        #[Assert\Email]
        #[Assert\NotBlank]
        public ?string $email = null,

        #[Assert\Length(min: 8)]
        #[Assert\NotBlank]
        public ?string $plainPassword = null,
    ) {
    }
}
