<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\VerificationEmailSender;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Deliberately does NOT compose RegisterUserHandler: that handler flushes
 * before a role could be assigned, so a failure between its flush and a
 * second role-setting flush would close the wizard with a role-less user. On
 * an empty database its duplicate checks are vacuous anyway. Everything here
 * happens in one transaction, serialized by an advisory lock; the
 * verification email is sent only after the transaction commits.
 */
final readonly class CreateInstallAdminHandler
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
        private VerificationEmailSender $verificationEmailSender,
    ) {
    }

    /** @throws DomainErrors */
    public function __invoke(CreateInstallAdminCommand $command): User
    {
        $user = $this->em->wrapInTransaction(function () use ($command): User {
            // Concurrent install submissions must not both observe an unclaimed
            // install — the advisory lock serializes them for this transaction.
            $this->em->getConnection()->executeStatement(
                "SELECT pg_advisory_xact_lock(hashtext('install_admin'))",
            );

            if (0 !== $this->users->countHumans()) {
                throw new DomainErrors(['email' => 'account.install.error.already_installed']);
            }

            $user = new User(
                fullName: $command->fullName,
                email: $command->email,
            );
            $user->password = $this->passwordHasher->hashPassword($user, $command->plainPassword);
            $user->roles = ['ROLE_ADMIN'];
            $this->em->persist($user);

            return $user;
        });

        try {
            $this->verificationEmailSender->send($user);
        } catch (\Throwable) {
            // Account exists either way — verification can be resent from the
            // check-email page after the first login attempt.
        }

        return $user;
    }
}
