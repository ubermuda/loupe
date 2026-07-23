<?php

namespace App\Module\Account\Command;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\VerificationEmailSender;

final readonly class ResendVerificationEmailHandler
{
    public function __construct(
        private UserRepository $users,
        private VerificationEmailSender $verificationEmailSender,
    ) {
    }

    /**
     * Completes silently for unknown or already-verified accounts, and on
     * mail-transport failure — the response must not reveal account state.
     */
    public function __invoke(ResendVerificationEmailCommand $command): void
    {
        $user = $this->users->findOneByEmail($command->email);

        if (!$user instanceof User || $user->isVerified()) {
            return;
        }

        try {
            $this->verificationEmailSender->send($user);
        } catch (\Throwable) {
        }
    }
}
