<?php

namespace App\Module\Account\Command;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\PasswordResetEmailSender;

final readonly class RequestPasswordResetHandler
{
    public function __construct(
        private UserRepository $users,
        private PasswordResetEmailSender $passwordResetEmailSender,
    ) {
    }

    /**
     * Always completes silently: whether the account exists, already has an
     * active reset token, or the email failed to enqueue must not be observable
     * (anti-enumeration policy).
     */
    public function __invoke(RequestPasswordResetCommand $command): void
    {
        $user = $this->users->findOneByEmail($command->email);

        if (!$user instanceof User) {
            return;
        }

        if ($user->hasActivePasswordResetToken()) {
            return;
        }

        try {
            $this->passwordResetEmailSender->send($user);
        } catch (\Throwable) {
        }
    }
}
