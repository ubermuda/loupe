<?php

namespace App\Tests\Module\Account\Repository;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

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

    public function test_the_agent_user_exists_and_cannot_be_signed_into(): void
    {
        $agent = $this->repository->agent();

        self::assertTrue($agent->isAgent());
        self::assertFalse($agent->hasUsablePassword());
        self::assertSame(['ROLE_USER'], $agent->getRoles());

        $this->expectException(UserNotFoundException::class);
        $this->repository->loadUserByIdentifier($agent->email);
    }

    public function test_the_agent_is_not_counted_as_a_user(): void
    {
        // Guard: without a populated table both counts could be zero for
        // reasons unrelated to the exclusion.
        $this->em->persist(new User(username: 'counted-human', fullName: 'Counted', email: 'counted-human@example.com', password: 'x'));
        $this->em->flush();

        $humans = $this->repository->countHumans();
        self::assertGreaterThan(0, $humans);
        self::assertSame($humans + 1, $this->repository->count([]));
        self::assertSame($humans, $this->repository->countActive());
    }
}
