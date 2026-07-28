<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Puts the database in the state a completed install leaves behind: one
 * administrator account. Registration refuses to create the *first* account on
 * an instance (that is the install wizard's job), so any test exercising
 * sign-up has to say that the instance is already installed — the test database
 * starts empty.
 */
final class InstalledInstance
{
    /** Deliberately unlike anything the sign-up tests themselves register. */
    public const string EMAIL = 'installed-admin@example.test';

    public static function ensure(EntityManagerInterface $em): User
    {
        $admin = new User(username: 'installed-admin', fullName: 'Installed Admin', email: self::EMAIL);
        $admin->password = 'not-a-real-hash';
        $admin->roles = ['ROLE_ADMIN'];
        $admin->emailVerifiedAt = new \DateTimeImmutable();

        $em->persist($admin);
        $em->flush();

        return $admin;
    }
}
