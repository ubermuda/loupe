<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command;

use App\Module\Account\Command\LinkSocialAccountCommand;
use App\Module\Account\Command\LinkSocialAccountHandler;
use App\Module\Account\Entity\SocialProvider;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\SocialProfile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class LinkSocialAccountHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private LinkSocialAccountHandler $handler;
    private UserRepository $users;
    private UserPasswordHasherInterface $passwordHasher;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $handler = self::getContainer()->get(LinkSocialAccountHandler::class);
        self::assertInstanceOf(LinkSocialAccountHandler::class, $handler);
        $this->handler = $handler;

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $this->passwordHasher = $hasher;
    }

    /**
     * @param non-empty-string $email
     * @param non-empty-string $username
     */
    private function persistPasswordUser(string $email, string $username, string $plainPassword): User
    {
        $user = new User(username: $username, fullName: 'U', email: $email, password: 'placeholder');
        $user->password = $this->passwordHasher->hashPassword($user, $plainPassword);
        $this->em->persist($user);

        return $user;
    }

    public function test_linking_revokes_an_outstanding_verification_link(): void
    {
        $user = $this->persistPasswordUser('link-pending@example.com', 'linkpending', 'correct horse');
        $user->generateEmailVerificationToken();
        $this->em->flush();
        // Guard: without this the assertion below passes on a user that never
        // had a token, proving nothing about revocation.
        self::assertTrue($user->hasEmailVerificationToken());

        ($this->handler)(new LinkSocialAccountCommand(
            userId: (string) $user->id,
            profile: new SocialProfile(SocialProvider::Google, 'g-link-pending', 'link-pending@example.com', 'Link Pending', emailVerified: true),
            plainPassword: 'correct horse',
        ));

        $this->em->clear();
        $reloaded = $this->users->find($user->id);
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->emailVerifiedAt);
        // VerifyEmailController logs in whoever presents a valid token and never
        // asks whether the account is already verified, so an outstanding link
        // here is a live credential rather than a stale no-op.
        self::assertFalse($reloaded->hasEmailVerificationToken());
    }

    public function test_an_unverified_provider_email_leaves_the_verification_link_alone(): void
    {
        $user = $this->persistPasswordUser('link-unverified@example.com', 'linkunverified', 'correct horse');
        $user->generateEmailVerificationToken();
        $this->em->flush();

        ($this->handler)(new LinkSocialAccountCommand(
            userId: (string) $user->id,
            profile: new SocialProfile(SocialProvider::Google, 'g-link-unverified', 'link-unverified@example.com', 'Link Unverified', emailVerified: false),
            plainPassword: 'correct horse',
        ));

        $this->em->clear();
        $reloaded = $this->users->find($user->id);
        self::assertNotNull($reloaded);
        // The provider proved nothing about this address, so the user's own
        // pending verification is still the only thing that can verify it.
        self::assertNull($reloaded->emailVerifiedAt);
        self::assertTrue($reloaded->hasEmailVerificationToken());
    }
}
