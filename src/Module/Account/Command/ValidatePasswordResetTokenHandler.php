<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;

final readonly class ValidatePasswordResetTokenHandler
{
    public function __construct(
        private UserRepository $users,
    ) {
    }

    public function __invoke(ValidatePasswordResetTokenCommand $command): ValidatePasswordResetTokenView
    {
        $user = $this->users->findByPasswordResetToken($command->token);

        return new ValidatePasswordResetTokenView(
            $user instanceof User && $user->isPasswordResetTokenValid($command->token) ? $user : null,
        );
    }
}
