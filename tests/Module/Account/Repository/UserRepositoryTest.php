<?php

namespace App\Tests\Module\Account\Repository;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UserRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(UserRepository::class);
    }

    public function test_count_active_excludes_disabled_users(): void
    {
        $baseline = $this->repository->countActive();

        $active = new User(username: 'active-user', fullName: 'Active User', email: 'active-user@example.com', password: 'x');
        $disabled = new User(username: 'disabled-user', fullName: 'Disabled User', email: 'disabled-user@example.com', password: 'x');
        $disabled->disabledAt = new \DateTimeImmutable();
        $this->em->persist($active);
        $this->em->persist($disabled);
        $this->em->flush();

        self::assertSame($baseline + 1, $this->repository->countActive());
    }
}
