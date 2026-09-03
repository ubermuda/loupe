<?php

namespace App\Module\Account\Command;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class ResetPasswordHandler
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
        private Auditor $auditor,
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

        $this->auditor->record(
            'account.password_reset',
            AuditOutcome::Success,
            ['userId' => (string) $user->id],
            new AuditSubject('user', (string) $user->id),
        );

        return $user;
    }
}
