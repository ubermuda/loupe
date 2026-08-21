<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Deletion;

use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SuspendedByForeignKeyTest extends KernelTestCase
{
    public function test_deleting_the_suspending_admin_nulls_the_attribution(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $admin = new User(fullName: 'Admin', email: 'fk-admin@example.com');
        $victim = new User(fullName: 'Victim', email: 'fk-victim@example.com');
        $victim->suspendedAt = new \DateTimeImmutable();
        $victim->suspendedBy = $admin;

        $em->persist($admin);
        $em->persist($victim);
        $em->flush();

        $adminId = (string) $admin->id;
        $victimId = (string) $victim->id;

        $em->getConnection()->executeStatement('DELETE FROM users WHERE id = :id', ['id' => $adminId]);
        $em->clear();

        $reloaded = $em->find(User::class, $victimId);
        self::assertInstanceOf(User::class, $reloaded);
        self::assertTrue($reloaded->isSuspended());
        self::assertNull($reloaded->suspendedBy);
    }
}
