<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Ensures the given email is a verified administrator, creating the account when
 * it does not exist and leaving an existing one's password alone. Cannot compose
 * CreateInstallAdminHandler — that refuses once any account exists, which is the
 * state this recovers from — and sends no verification email, because on an
 * instance being recovered from a shell mail may be the thing that is broken.
 */
final readonly class CreateAdminUserHandler
{
    private const int MAX_FULL_NAME_LENGTH = 150;

    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
        private PromoteUserToAdminHandler $promoteUserToAdmin,
        private MarkEmailVerifiedHandler $markEmailVerified,
        private LoggerInterface $logger,
    ) {
    }

    /** @throws DomainErrors */
    public function __invoke(CreateAdminUserCommand $command): CreateAdminUserResult
    {
        $email = strtolower($command->email);

        $existing = $this->users->findOneByEmail($email);
        if (null !== $existing) {
            return new CreateAdminUserResult(
                user: $existing,
                created: false,
                promoted: ($this->promoteUserToAdmin)(new PromoteUserToAdminCommand($email)),
                verified: ($this->markEmailVerified)(new MarkEmailVerifiedCommand($email))->verified,
            );
        }

        // A name only if the operator gave one. Nothing is derived from the
        // address: an account with no display name renders its email, which is
        // truthful, where a derived one would look like something the person
        // chose.
        $fullName = trim($command->fullName ?? '');

        $user = new User(
            fullName: '' !== $fullName ? substr($fullName, 0, self::MAX_FULL_NAME_LENGTH) : null,
            email: $email,
        );
        $user->password = $this->passwordHasher->hashPassword($user, $command->plainPassword);
        $user->roles = ['ROLE_ADMIN'];
        $user->emailVerifiedAt = new \DateTimeImmutable();

        $this->em->persist($user);
        $this->em->flush();

        $this->logger->info('account.admin.created_from_console', ['userId' => (string) $user->id]);

        return new CreateAdminUserResult(user: $user, created: true, promoted: false, verified: false);
    }
}
