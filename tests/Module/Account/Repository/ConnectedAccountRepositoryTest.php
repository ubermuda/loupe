<?php

namespace App\Tests\Module\Account\Repository;

use App\Module\Account\Entity\ConnectedAccount;
use App\Module\Account\Entity\SocialProvider;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\ConnectedAccountRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ConnectedAccountRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ConnectedAccountRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(ConnectedAccountRepository::class);
    }

    public function test_find_one_by_provider_and_provider_user_id(): void
    {
        $user = $this->persistUser('jane@example.com', 'jane');
        $this->em->persist(new ConnectedAccount(
            user: $user,
            provider: SocialProvider::Google,
            providerUserId: 'g-123',
            email: 'jane@example.com',
        ));
        $this->em->flush();
        $this->em->clear();

        $found = $this->repository->findOneByProviderAndProviderUserId(SocialProvider::Google, 'g-123');

        self::assertNotNull($found);
        self::assertSame('jane@example.com', $found->user->email);
    }

    public function test_returns_null_for_unknown_subject(): void
    {
        self::assertNull($this->repository->findOneByProviderAndProviderUserId(SocialProvider::Google, 'nope'));
    }

    public function test_same_subject_id_on_another_provider_is_a_different_identity(): void
    {
        $user = $this->persistUser('sam@example.com', 'sam');
        $this->em->persist(new ConnectedAccount($user, SocialProvider::Google, 'shared-id'));
        $this->em->persist(new ConnectedAccount($user, SocialProvider::Github, 'shared-id'));
        $this->em->flush();
        $this->em->clear();

        self::assertNotNull($this->repository->findOneByProviderAndProviderUserId(SocialProvider::Google, 'shared-id'));
        self::assertNotNull($this->repository->findOneByProviderAndProviderUserId(SocialProvider::Github, 'shared-id'));
    }

    public function test_duplicate_provider_subject_violates_the_unique_constraint(): void
    {
        $first = $this->persistUser('first@example.com', 'first');
        $second = $this->persistUser('second@example.com', 'second');
        $this->em->persist(new ConnectedAccount($first, SocialProvider::Github, 'gh-1'));
        $this->em->flush();

        $this->em->persist(new ConnectedAccount($second, SocialProvider::Github, 'gh-1'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    /** @param non-empty-string $email */
    private function persistUser(string $email, string $username): User
    {
        $user = new User(fullName: 'Test User', email: $email);
        $this->em->persist($user);

        return $user;
    }
}
