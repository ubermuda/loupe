<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command;

use App\Module\Account\Command\JoinWaitlistHandler;
use App\Module\Account\Command\ResolveSocialLoginCommand;
use App\Module\Account\Command\ResolveSocialLoginHandler;
use App\Module\Account\Entity\ConnectedAccount;
use App\Module\Account\Entity\SocialProvider;
use App\Module\Account\Entity\User;
use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Event\UserRegistered;
use App\Module\Account\Repository\ConnectedAccountRepository;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Account\Service\InstallationState;
use App\Module\Account\Service\RegistrationGate;
use App\Module\Account\Service\SocialLoginOutcome;
use App\Module\Account\Service\SocialLoginRace;
use App\Module\Account\Service\SocialProfile;
use App\Module\Account\Service\UnverifiedProviderEmail;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Billing\Service\TrialProvisioner;
use App\Tests\Support\InstalledInstance;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

final class ResolveSocialLoginHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ResolveSocialLoginHandler $handler;
    private ConnectedAccountRepository $connectedAccounts;
    private UserRepository $users;
    private WaitlistEntryRepository $waitlistEntries;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->connectedAccounts = $container->get(ConnectedAccountRepository::class);
        $this->users = $container->get(UserRepository::class);
        $waitlistEntries = $container->get(WaitlistEntryRepository::class);
        self::assertInstanceOf(WaitlistEntryRepository::class, $waitlistEntries);
        $this->waitlistEntries = $waitlistEntries;

        // Sign-up refuses to create the first account on an instance; every
        // branch-D test below needs the install to already be complete.
        InstalledInstance::ensure($this->em);

        $this->handler = $this->buildHandler(
            new RegistrationGate($this->openFlags(), $this->users, new InstallationState($this->users)),
            $this->createStub(EventDispatcherInterface::class),
        );
    }

    private function buildHandler(RegistrationGate $gate, EventDispatcherInterface $dispatcher): ResolveSocialLoginHandler
    {
        $joinWaitlist = self::getContainer()->get(JoinWaitlistHandler::class);
        self::assertInstanceOf(JoinWaitlistHandler::class, $joinWaitlist);

        return new ResolveSocialLoginHandler(
            $this->connectedAccounts,
            $this->users,
            $this->em,
            $gate,
            $joinWaitlist,
            $this->waitlistEntries,
            new NullLogger(),
            $dispatcher,
        );
    }

    private function neverDispatches(): EventDispatcherInterface
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatch');

        return $dispatcher;
    }

    private function openFlags(): FeatureFlagService
    {
        $flags = $this->createStub(FeatureFlagService::class);
        $flags->method('getIntValue')->willReturn(0); // unlimited — gate stays open
        $flags->method('isEnabled')->willReturn(true); // registration switched on

        return $flags;
    }

    private function resolvedUser(SocialLoginOutcome $outcome): User
    {
        self::assertFalse($outcome->waitlisted);
        self::assertNotNull($outcome->user);

        return $outcome->user;
    }

    public function test_a_failing_post_commit_listener_does_not_fail_the_login(): void
    {
        // The user and connected account have committed by dispatch time; a
        // listener blowing up (e.g. trial provisioning hitting a transient DB
        // error) must not fail the OAuth callback — the account exists, so a
        // retry would take the existing-identity branch and hide the error.
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException(new \RuntimeException('provisioning down'));
        $handler = $this->buildHandler(new RegistrationGate($this->openFlags(), $this->users, new InstallationState($this->users)), $dispatcher);

        $outcome = $handler(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Google, 'g-listener-fail', 'listener-fail@example.com', 'Listener Fail', emailVerified: true),
        ));

        self::assertNotNull($this->resolvedUser($outcome)->id);
    }

    // -------------------------------------------------------------------------
    // Branch A — an already-linked identity always wins, whatever the email says
    // -------------------------------------------------------------------------

    public function test_existing_identity_logs_in_even_when_the_provider_email_changed(): void
    {
        $owner = $this->persistUser('owner@example.com');
        $this->em->persist(new ConnectedAccount($owner, SocialProvider::Google, 'g-1', 'owner@example.com'));
        $this->em->flush();

        // A different, unverified, and unrelated email must not change the outcome.
        $outcome = ($this->handler)(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Google, 'g-1', 'someone-else@example.com', 'Owner', emailVerified: false),
        ));

        self::assertFalse($outcome->requiresPasswordLink);
        self::assertSame($owner->id, $this->resolvedUser($outcome)->id);
    }

    public function test_existing_identity_logs_in_when_the_provider_reports_no_email(): void
    {
        $owner = $this->persistUser('owner2@example.com');
        $this->em->persist(new ConnectedAccount($owner, SocialProvider::Github, 'gh-1'));
        $this->em->flush();

        $outcome = ($this->handler)(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Github, 'gh-1', null, null, emailVerified: false),
        ));

        self::assertSame($owner->id, $this->resolvedUser($outcome)->id);
    }

    public function test_resolving_the_same_identity_twice_reuses_the_link(): void
    {
        $profile = new SocialProfile(SocialProvider::Google, 'g-twice', 'twice@example.com', 'Twice', emailVerified: true);

        $first = ($this->handler)(new ResolveSocialLoginCommand($profile));
        $second = ($this->handler)(new ResolveSocialLoginCommand($profile));

        self::assertSame($this->resolvedUser($first)->id, $this->resolvedUser($second)->id);
        self::assertCount(1, $this->connectedAccounts->findBy(['providerUserId' => 'g-twice']));
    }

    // -------------------------------------------------------------------------
    // Unverified provider email — rejected outright, nothing written
    // -------------------------------------------------------------------------

    public function test_unverified_email_matching_a_password_account_is_rejected_without_linking(): void
    {
        $victim = $this->persistUser('victim@example.com', password: 'hashed');
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
        $target = $this->persistUser('social-only@example.com');
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
        $existing = $this->persistUser('collide@example.com', password: 'hashed');
        $this->em->flush();

        $outcome = ($this->handler)(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Google, 'g-collide', 'collide@example.com', 'Collide', emailVerified: true),
        ));

        self::assertTrue($outcome->requiresPasswordLink);
        self::assertSame($existing->id, $this->resolvedUser($outcome)->id);

        $this->em->clear();
        self::assertSame([], $this->connectedAccounts->findBy(['providerUserId' => 'g-collide']));
    }

    // -------------------------------------------------------------------------
    // Branch C — verified email matching a password-less account
    // -------------------------------------------------------------------------

    public function test_verified_email_matching_a_password_less_account_links_and_retro_verifies(): void
    {
        $existing = $this->persistUser('linkme@example.com');
        $this->em->flush();
        self::assertNull($existing->emailVerifiedAt);

        $outcome = ($this->handler)(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Google, 'g-link', 'linkme@example.com', 'Link Me', emailVerified: true),
        ));

        self::assertFalse($outcome->requiresPasswordLink);
        self::assertSame($existing->id, $this->resolvedUser($outcome)->id);

        $this->em->clear();
        $reloaded = $this->users->find($existing->id);
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->emailVerifiedAt);
        self::assertNotNull($this->connectedAccounts->findOneByProviderAndProviderUserId(SocialProvider::Google, 'g-link'));
    }

    public function test_an_already_verified_account_keeps_its_original_verification_date(): void
    {
        $verifiedAt = new \DateTimeImmutable('2026-01-01 10:00:00');
        $existing = $this->persistUser('early@example.com');
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
        $user = $this->resolvedUser($outcome);
        self::assertSame('fresh@example.com', $user->email);
        self::assertSame('Fresh Face', $user->fullName);
        self::assertNotNull($user->emailVerifiedAt);
        self::assertFalse($user->hasUsablePassword());
        self::assertNotNull($this->connectedAccounts->findOneByProviderAndProviderUserId(SocialProvider::Github, 'gh-fresh'));
    }

    public function test_creating_an_account_converts_a_matching_waitlist_row(): void
    {
        // The address joined the waitlist earlier (directly, or via a
        // previous at-cap OAuth attempt) and is only now creating an account
        // because the cap is open — that row must not linger as "waiting".
        $entry = new WaitlistEntry('oauth-joined-earlier@example.com');
        $this->em->persist($entry);
        $this->em->flush();

        ($this->handler)(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Github, 'gh-was-waitlisted', 'oauth-joined-earlier@example.com', 'Was Waitlisted', emailVerified: true),
        ));

        $this->em->clear();
        $reloaded = $this->waitlistEntries->findOneByEmail('oauth-joined-earlier@example.com');
        self::assertNotNull($reloaded?->convertedAt);
    }

    public function test_creating_an_account_dispatches_user_registered(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())->method('dispatch')
            ->with(self::callback(
                static fn (object $event): bool => $event instanceof UserRegistered && 'dispatch-social@example.com' === $event->user->email,
            ))
            ->willReturnArgument(0);
        $handler = $this->buildHandler(new RegistrationGate($this->openFlags(), $this->users, new InstallationState($this->users)), $dispatcher);

        $outcome = $handler(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Github, 'gh-dispatch', 'dispatch-social@example.com', 'Dispatch Me', emailVerified: true),
        ));

        self::assertSame('dispatch-social@example.com', $this->resolvedUser($outcome)->email);
    }

    public function test_creating_an_account_provisions_a_trial_billing_profile(): void
    {
        // Uses the real event dispatcher, so the Billing listener actually
        // runs — the trial clock starts at (social) registration.
        $this->em->persist(new FeatureFlag(name: 'billing.enabled', type: FeatureFlagType::Bool, value: true));
        $this->em->flush();

        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $handler = $this->buildHandler(new RegistrationGate($this->openFlags(), $this->users, new InstallationState($this->users)), $dispatcher);

        $outcome = $handler(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Github, 'gh-trial', 'social-trial@example.com', 'Trial Clock', emailVerified: true),
        ));

        $profiles = self::getContainer()->get(BillingProfileRepository::class);
        self::assertInstanceOf(BillingProfileRepository::class, $profiles);
        $profile = $profiles->findOneByUser($this->resolvedUser($outcome));
        self::assertNotNull($profile);

        // No billing.trial_days flag row exists in the test DB → default applies.
        $expected = new \DateTimeImmutable(sprintf('+%d days', TrialProvisioner::DEFAULT_TRIAL_DAYS));
        self::assertGreaterThan($expected->modify('-1 hour'), $profile->trialEndsAt);
        self::assertLessThan($expected->modify('+1 hour'), $profile->trialEndsAt);
    }

    public function test_existing_identity_and_auto_link_logins_dispatch_no_event(): void
    {
        // Branch A (already-linked identity), branch B (verified email
        // colliding with a password-protected account) and branch C (auto-link
        // to a password-less account) all return before the dispatch point —
        // the event marks new registrations only.
        $owner = $this->persistUser('no-event-owner@example.com');
        $this->em->persist(new ConnectedAccount($owner, SocialProvider::Google, 'g-no-event', 'no-event-owner@example.com'));
        $this->persistUser('no-event-collide@example.com', password: 'hashed');
        $this->persistUser('no-event-linkme@example.com');
        $this->em->flush();

        $handler = $this->buildHandler(new RegistrationGate($this->openFlags(), $this->users, new InstallationState($this->users)), $this->neverDispatches());

        $handler(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Google, 'g-no-event', 'no-event-owner@example.com', 'Owner', emailVerified: true),
        ));
        $collideOutcome = $handler(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Google, 'g-no-event-collide', 'no-event-collide@example.com', 'Collide', emailVerified: true),
        ));
        self::assertTrue($collideOutcome->requiresPasswordLink);
        $handler(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Google, 'g-no-event-link', 'no-event-linkme@example.com', 'Link Me', emailVerified: true),
        ));
    }

    public function test_a_provider_that_sends_no_name_leaves_the_account_without_one(): void
    {
        $outcome = ($this->handler)(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Google, 'g-noname', 'nameless@example.com', null, emailVerified: true),
        ));

        // Nothing is derived from the address any more: the account simply has
        // no display name, and renders its email until its owner sets one.
        self::assertNull($this->resolvedUser($outcome)->fullName);
        self::assertSame('nameless@example.com', $this->resolvedUser($outcome)->displayName());
    }

    // -------------------------------------------------------------------------
    // Uniqueness race. dama wraps each test in one transaction, so two
    // overlapping DB transactions cannot be expressed here — stub the flush.
    // -------------------------------------------------------------------------

    public function test_a_uniqueness_race_on_flush_surfaces_as_social_login_race(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(fn (callable $func) => $func());
        $em->method('getConnection')->willReturn($this->em->getConnection());
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
        $users->method('countHumans')->willReturn(1); // installation complete

        $waitlistEntries = $this->createStub(WaitlistEntryRepository::class);
        $waitlistEntries->method('findOneByEmail')->willReturn(null);

        // JoinWaitlistHandler is `final`, so PHPUnit cannot stub/double it — use
        // the real container instance instead. Safe here because the gate stays
        // open (openFlags()), so branch D never reaches the waitlist call.
        $joinWaitlist = self::getContainer()->get(JoinWaitlistHandler::class);
        self::assertInstanceOf(JoinWaitlistHandler::class, $joinWaitlist);

        $handler = new ResolveSocialLoginHandler(
            $connectedAccounts,
            $users,
            $em,
            new RegistrationGate($this->openFlags(), $users, new InstallationState($users)),
            $joinWaitlist,
            $waitlistEntries,
            new NullLogger(),
            $this->neverDispatches(),
        );

        $this->expectException(SocialLoginRace::class);

        $handler(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Google, 'g-race', 'race@example.com', 'Racer', emailVerified: true),
        ));
    }

    // -------------------------------------------------------------------------
    // Branch D at cap — diverts to the waitlist instead of creating a user
    // -------------------------------------------------------------------------

    public function test_at_cap_new_oauth_user_is_waitlisted_and_no_user_is_created(): void
    {
        $before = \count($this->users->findAll());
        $this->closeRegistration();

        $outcome = ($this->handler)(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Github, 'gh-waitlisted', 'waitlisted-oauth@example.com', 'Waitlisted', emailVerified: true),
        ));

        self::assertTrue($outcome->waitlisted);
        self::assertNull($outcome->user);
        self::assertFalse($outcome->requiresPasswordLink);

        $this->em->clear();
        // Only the gate-filler user created by closeRegistration() exists —
        // the OAuth profile itself created no account.
        self::assertCount($before + 1, $this->users->findAll());

        $entries = self::getContainer()->get(WaitlistEntryRepository::class);
        self::assertNotNull($entries->findOneByEmail('waitlisted-oauth@example.com'));
    }

    public function test_at_cap_waitlisted_oauth_dispatches_no_user_registered_event(): void
    {
        $realGate = $this->closeRegistration();
        $handler = $this->buildHandler($realGate, $this->neverDispatches());

        $outcome = $handler(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Github, 'gh-no-event-cap', 'no-event-at-cap@example.com', 'Capped', emailVerified: true),
        ));

        self::assertTrue($outcome->waitlisted);
    }

    public function test_at_cap_unverified_email_is_rejected_not_waitlisted(): void
    {
        $this->closeRegistration();

        $this->expectException(UnverifiedProviderEmail::class);

        try {
            ($this->handler)(new ResolveSocialLoginCommand(
                new SocialProfile(SocialProvider::Github, 'gh-unverified-at-cap', 'unverified-at-cap@example.com', 'Nope', emailVerified: false),
            ));
        } finally {
            $entries = self::getContainer()->get(WaitlistEntryRepository::class);
            self::assertNull($entries->findOneByEmail('unverified-at-cap@example.com'));
        }
    }

    public function test_at_cap_existing_identity_still_logs_in(): void
    {
        $owner = $this->persistUser('cap-owner@example.com');
        $this->em->persist(new ConnectedAccount($owner, SocialProvider::Google, 'g-cap-owner', 'cap-owner@example.com'));
        $this->em->flush();
        $this->closeRegistration();

        $outcome = ($this->handler)(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Google, 'g-cap-owner', 'cap-owner@example.com', 'Cap Owner', emailVerified: true),
        ));

        self::assertFalse($outcome->waitlisted);
        self::assertSame($owner->id, $this->resolvedUser($outcome)->id);
    }

    public function test_at_cap_password_collision_still_requires_a_password_link(): void
    {
        $existing = $this->persistUser('cap-collide@example.com', password: 'hashed');
        $this->em->flush();
        $this->closeRegistration();

        $outcome = ($this->handler)(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Google, 'g-cap-collide', 'cap-collide@example.com', 'Cap Collide', emailVerified: true),
        ));

        self::assertFalse($outcome->waitlisted);
        self::assertTrue($outcome->requiresPasswordLink);
        self::assertSame($existing->id, $this->resolvedUser($outcome)->id);
    }

    public function test_at_cap_password_less_account_still_auto_links(): void
    {
        $existing = $this->persistUser('cap-linkme@example.com');
        $this->em->flush();
        $this->closeRegistration();

        $outcome = ($this->handler)(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Google, 'g-cap-linkme', 'cap-linkme@example.com', 'Cap Link Me', emailVerified: true),
        ));

        self::assertFalse($outcome->waitlisted);
        self::assertFalse($outcome->requiresPasswordLink);
        self::assertSame($existing->id, $this->resolvedUser($outcome)->id);
    }

    private function closeRegistration(): RegistrationGate
    {
        $this->persistUser('gate-filler-'.bin2hex(random_bytes(4)).'@example.com');
        $this->em->flush();

        $flag = new FeatureFlag(name: RegistrationGate::CAP_FLAG, type: FeatureFlagType::Int, value: $this->users->countActive());
        $this->em->persist($flag);
        $this->em->flush();

        // Rebuild the handler with a gate that reads real committed flag/user
        // state (setUp() wired a permanently-open stub gate).
        $realGate = self::getContainer()->get(RegistrationGate::class);
        self::assertInstanceOf(RegistrationGate::class, $realGate);

        $this->handler = $this->buildHandler($realGate, $this->createStub(EventDispatcherInterface::class));

        return $realGate;
    }

    /** @param non-empty-string $email */
    private function persistUser(string $email, ?string $password = null): User
    {
        $user = new User(fullName: 'Test User', email: $email, password: $password);
        $this->em->persist($user);

        return $user;
    }
}
