<?php

namespace App\Module\Account\Repository;

use App\Module\Account\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface, UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->findOneBy(['email' => strtolower($identifier)]);
        if (!$user instanceof User) {
            throw new UserNotFoundException(sprintf('User "%s" not found.', $identifier));
        }

        return $user;
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }
        $user->password = $newHashedPassword;
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    public function findByEmailVerificationToken(string $token): ?User
    {
        $hash = hash('sha256', $token);

        return $this->findOneBy(['emailVerificationTokenHash' => $hash]);
    }

    public function findByPasswordResetToken(string $token): ?User
    {
        $hash = hash('sha256', $token);

        return $this->findOneBy(['passwordResetTokenHash' => $hash]);
    }

    public function findByAccountDeletionToken(string $token): ?User
    {
        $hash = hash('sha256', $token);

        return $this->findOneBy(['accountDeletionTokenHash' => $hash]);
    }

    public function findOneByEmail(?string $email): ?User
    {
        if (null === $email) {
            return null;
        }

        return $this->findOneBy(['email' => strtolower($email)]);
    }

    public function findOneByUsername(?string $username): ?User
    {
        if (null === $username) {
            return null;
        }

        return $this->findOneBy(['username' => $username]);
    }

    public function countAll(): int
    {
        return $this->count([]);
    }

    /**
     * Users who currently occupy a registration-cap spot. Disabled accounts
     * (trial ended unconverted, or subscription canceled) do not count — their
     * spot is exactly what the trial-end sweep frees up.
     */
    public function countActive(): int
    {
        return $this->count(['disabledAt' => null]);
    }
}
