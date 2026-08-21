<?php

declare(strict_types=1);

namespace App\Module\Account\Form\Admin;

use App\Module\Account\Entity\User;
use Symfony\Component\Validator\Constraints as Assert;

final class AdminUserRequest
{
    public function __construct(
        #[Assert\Length(max: User::MAX_FULL_NAME_LENGTH, normalizer: 'trim')]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $fullName = null,

        #[Assert\Email]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $email = null,
        public bool $isAdmin = false,
        public bool $isVerified = false,
    ) {
    }

    public static function fromUser(User $user): self
    {
        return new self(
            fullName: $user->fullName,
            email: $user->email,
            isAdmin: in_array('ROLE_ADMIN', $user->roles, true),
            isVerified: $user->isVerified(),
        );
    }
}
