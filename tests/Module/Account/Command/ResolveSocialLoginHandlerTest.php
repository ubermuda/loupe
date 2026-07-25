<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command;

use App\Module\Account\Command\ResolveSocialLoginCommand;
use App\Module\Account\Command\ResolveSocialLoginHandler;
use App\Module\Account\Entity\ConnectedAccount;
use App\Module\Account\Entity\SocialProvider;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\ConnectedAccountRepository;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\SocialLoginRace;
use App\Module\Account\Service\SocialProfile;
use App\Module\Account\Service\UnverifiedProviderEmail;
use App\Module\Account\Service\UsernameGenerator;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ResolveSocialLoginHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ResolveSocialLoginHandler $handler;
    private ConnectedAccountRepository $connectedAccounts;
    private UserRepository $users;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->connectedAccounts = $container->get(ConnectedAccountRepository::class);
        $this->users = $container->get(UserRepository::class);
        $this->handler = new ResolveSocialLoginHandler(
            $this->connectedAccounts,
            $this->users,
            $this->em,
            new UsernameGenerator($this->users),
        );
    }

    // -------------------------------------------------------------------------
    // Branch A — an already-linked identity always wins, whatever the email says
    // -------------------------------------------------------------------------

    public function test_existing_identity_logs_in_even_when_the_provider_email_changed(): void
    {
        $owner = $this->persistUser('owner@example.com', 'owner');
        $this->em->persist(new ConnectedAccount($owner, SocialProvider::Google, 'g-1', 'owner@example.com'));
        $this->em->flush();

        // A different, unverified, and unrelated email must not change the outcome.
        $outcome = ($this->handler)(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Google, 'g-1', 'someone-else@example.com', 'Owner', emailVerified: false),
        ));

        self::assertFalse($outcome->requiresPasswordLink);
        self::assertSame($owner->id, $outcome->user->id);
    }

    public function test_existing_identity_logs_in_when_the_provider_reports_no_email(): void
    {
        $owner = $this->persistUser('owner2@example.com', 'owner2');
        $this->em->persist(new ConnectedAccount($owner, SocialProvider::Github, 'gh-1'));
        $this->em->flush();

        $outcome = ($this->handler)(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Github, 'gh-1', null, null, emailVerified: false),
        ));

        self::assertSame($owner->id, $outcome->user->id);
    }

    public function test_resolving_the_same_identity_twice_reuses_the_link(): void
    {
        $profile = new SocialProfile(SocialProvider::Google, 'g-twice', 'twice@example.com', 'Twice', emailVerified: true);

        $first = ($this->handler)(new ResolveSocialLoginCommand($profile));
        $second = ($this->handler)(new ResolveSocialLoginCommand($profile));

        self::assertSame($first->user->id, $second->user->id);
        self::assertCount(1, $this->connectedAccounts->findBy(['providerUserId' => 'g-twice']));
    }

    // -------------------------------------------------------------------------
    // Unverified provider email — rejected outright, nothing written
    // -------------------------------------------------------------------------

    public function test_unverified_email_matching_a_password_account_is_rejected_without_linking(): void
    {
        $victim = $this->persistUser('victim@example.com', 'victim', password: 'hashed');
        $this->em->flush();

        try {
            ($this->handler)(new ResolveSocialLoginCommand(
                new SocialProfile(SocialProvider::Github, 'gh-attacker', 'victim@example.com', 'Attacker', emailVerified: false),
            ));
            self::fail('Expected UnverifiedProviderEmail.');
        } catch (UnverifiedProviderEmail) {
            // expected
        }

        $this->em->clear();
        self::assertSame([], $this->connectedAccounts->findBy(['providerUserId' => 'gh-attacker']));
        self::assertNotNull($this->users->find($victim->id));
    }

    public function test_unverified_email_matching_a_password_less_account_is_rejected(): void
    {
        $target = $this->persistUser('social-only@example.com', 'socialonly');
        $this->em->flush();

        $this->expectException(UnverifiedProviderEmail::class);

        try {
            ($this->handler)(new ResolveSocialLoginCommand(
                new SocialProfile(SocialProvider::Github, 'gh-attacker-2', 'social-only@example.com', null, emailVerified: false),
            ));
        } finally {
            self::assertSame([], $this->connectedAccounts->findBy(['providerUserId' => 'gh-attacker-2']));
            self::assertNull($target->emailVerifiedAt);
        }
    }

    public function test_unverified_email_with_no_match_creates_nothing(): void
    {
        $before = \count($this->users->findAll());

        $this->expectException(UnverifiedProviderEmail::class);

        try {
            ($this->handler)(new ResolveSocialLoginCommand(
                new SocialProfile(SocialProvider::Github, 'gh-new', 'brand-new@example.com', 'New', emailVerified: false),
            ));
        } finally {
            $this->em->clear();
            self::assertCount($before, $this->users->findAll());
        }
    }

    public function test_verified_flag_without_an_email_is_rejected(): void
    {
        $this->expectException(UnverifiedProviderEmail::class);

        ($this->handler)(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Github, 'gh-no-email', null, 'No Email', emailVerified: true),
        ));
    }

    // -------------------------------------------------------------------------
    // Branch B — verified email colliding with a password-bearing account
    // -------------------------------------------------------------------------

    public function test_verified_email_matching_a_password_account_requires_a_password_link(): void
    {
        $existing = $this->persistUser('collide@example.com', 'collide', password: 'hashed');
        $this->em->flush();

        $outcome = ($this->handler)(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Google, 'g-collide', 'collide@example.com', 'Collide', emailVerified: true),
        ));

        self::assertTrue($outcome->requiresPasswordLink);
        self::assertSame($existing->id, $outcome->user->id);

        $this->em->clear();
        self::assertSame([], $this->connectedAccounts->findBy(['providerUserId' => 'g-collide']));
    }

    // -------------------------------------------------------------------------
    // Branch C — verified email matching a password-less account
    // -------------------------------------------------------------------------

    public function test_verified_email_matching_a_password_less_account_links_and_retro_verifies(): void
    {
        $existing = $this->persistUser('linkme@example.com', 'linkme');
        $this->em->flush();
        self::assertNull($existing->emailVerifiedAt);

        $outcome = ($this->handler)(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Google, 'g-link', 'linkme@example.com', 'Link Me', emailVerified: true),
        ));

        self::assertFalse($outcome->requiresPasswordLink);
        self::assertSame($existing->id, $outcome->user->id);

        $this->em->clear();
        $reloaded = $this->users->find($existing->id);
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->emailVerifiedAt);
        self::assertNotNull($this->connectedAccounts->findOneByProviderAndProviderUserId(SocialProvider::Google, 'g-link'));
    }

    public function test_an_already_verified_account_keeps_its_original_verification_date(): void
    {
        $verifiedAt = new \DateTimeImmutable('2026-01-01 10:00:00');
        $existing = $this->persistUser('early@example.com', 'early');
        $existing->emailVerifiedAt = $verifiedAt;
        $this->em->flush();

        ($this->handler)(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Google, 'g-early', 'early@example.com', 'Early', emailVerified: true),
        ));

        $this->em->clear();
        $reloaded = $this->users->find($existing->id);
        self::assertNotNull($reloaded);
        self::assertSame($verifiedAt->format('c'), $reloaded->emailVerifiedAt?->format('c'));
    }

    // -------------------------------------------------------------------------
    // Branch D — no match, create a verified account
    // -------------------------------------------------------------------------

    public function test_creates_a_verified_password_less_user_when_nothing_matches(): void
    {
        $outcome = ($this->handler)(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Github, 'gh-fresh', 'fresh@example.com', 'Fresh Face', emailVerified: true),
        ));

        self::assertFalse($outcome->requiresPasswordLink);
        self::assertSame('fresh@example.com', $outcome->user->email);
        self::assertSame('Fresh Face', $outcome->user->fullName);
        self::assertNotNull($outcome->user->emailVerifiedAt);
        self::assertFalse($outcome->user->hasUsablePassword());
        self::assertNotNull($this->connectedAccounts->findOneByProviderAndProviderUserId(SocialProvider::Github, 'gh-fresh'));
    }

    public function test_generated_username_avoids_a_seeded_collision(): void
    {
        $this->persistUser('taken@example.com', 'fresh-face');
        $this->em->flush();

        $outcome = ($this->handler)(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Github, 'gh-fresh-2', 'other@example.com', 'Fresh Face', emailVerified: true),
        ));

        self::assertNotSame('fresh-face', $outcome->user->username);
        self::assertStringStartsWith('fresh-face-', $outcome->user->username);
    }

    public function test_falls_back_to_the_email_local_part_when_the_provider_sends_no_name(): void
    {
        $outcome = ($this->handler)(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Google, 'g-noname', 'nameless@example.com', null, emailVerified: true),
        ));

        self::assertSame('nameless', $outcome->user->fullName);
        self::assertSame('nameless', $outcome->user->username);
    }

    // -------------------------------------------------------------------------
    // Uniqueness race. dama wraps each test in one transaction, so two
    // overlapping DB transactions cannot be expressed here — stub the flush.
    // -------------------------------------------------------------------------

    public function test_a_uniqueness_race_on_flush_surfaces_as_social_login_race(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('flush')->willThrowException(
            new UniqueConstraintViolationException(
                PdoDriverException::new(new \PDOException('duplicate key value violates unique constraint')),
                null,
            ),
        );

        $connectedAccounts = $this->createStub(ConnectedAccountRepository::class);
        $connectedAccounts->method('findOneByProviderAndProviderUserId')->willReturn(null);

        $users = $this->createStub(UserRepository::class);
        $users->method('findOneByEmail')->willReturn(null);
        $users->method('findOneByUsername')->willReturn(null);

        $handler = new ResolveSocialLoginHandler($connectedAccounts, $users, $em, new UsernameGenerator($users));

        $this->expectException(SocialLoginRace::class);

        $handler(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Google, 'g-race', 'race@example.com', 'Racer', emailVerified: true),
        ));
    }

    /** @param non-empty-string $email */
    private function persistUser(string $email, string $username, ?string $password = null): User
    {
        $user = new User(username: $username, fullName: 'Test User', email: $email, password: $password);
        $this->em->persist($user);

        return $user;
    }
}
