<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Repository\UserRepository;

final readonly class ConfirmAccountDeletionHandler
{
    public function __construct(
        private UserRepository $users,
    ) {
    }

    public function __invoke(ConfirmAccountDeletionCommand $command): ConfirmAccountDeletionView
    {
        $token = $command->token;
        if (null === $token) {
            return new ConfirmAccountDeletionView(null);
        }

        $user = $this->users->findByAccountDeletionToken($token);

        return new ConfirmAccountDeletionView(
            null !== $user && $user->isAccountDeletionTokenValid($token) ? $user : null,
        );
    }
}
