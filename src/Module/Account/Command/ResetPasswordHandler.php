<?php

namespace App\Module\Account\Command;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class ResetPasswordHandler
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * Returns the user whose password was changed, or null when the token is
     * unknown, expired or tampered with (e.g. consumed between the form render
     * and the submit).
     */
    public function __invoke(ResetPasswordCommand $command): ?User
    {
        $user = $this->users->findByPasswordResetToken($command->token);
        if (!$user instanceof User || !$user->isPasswordResetTokenValid($command->token)) {
            return null;
        }

        $user->clearPasswordResetToken();
        $user->password = $this->passwordHasher->hashPassword($user, $command->plainPassword);
        $this->em->flush();

        return $user;
    }
}
