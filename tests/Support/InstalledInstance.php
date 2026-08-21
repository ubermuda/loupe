<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

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

    public static function ensure(ContainerInterface $container): User
    {
        $em = $container->get(EntityManagerInterface::class);
        if (!$em instanceof EntityManagerInterface) {
            throw new \LogicException('The entity manager is not available from this container.');
        }

        $admin = new User(fullName: 'Installed Admin', email: self::EMAIL);
        $admin->password = 'not-a-real-hash';
        $admin->roles = ['ROLE_ADMIN'];
        $admin->emailVerifiedAt = new \DateTimeImmutable();
        // CreateInstallAdminHandler records acceptance, so an admin left behind
        // by a real install is never gated by RequireTermsAcceptanceListener.
        AcceptedTerms::stamp($admin, $container);

        $em->persist($admin);
        $em->flush();

        return $admin;
    }
}
