<?php

declare(strict_types=1);

namespace App\Module\Account\Form;

use App\Module\Account\Entity\User;
use Symfony\Component\Validator\Constraints as Assert;

class ProfileRequest
{
    public function __construct(
        #[Assert\Length(max: User::MAX_FULL_NAME_LENGTH, normalizer: 'trim')]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $fullName = null,
    ) {
    }
}
