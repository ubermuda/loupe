<?php

namespace App\Tests\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Command\RegisterUserCommand;
use App\Module\Account\Command\RegisterUserHandler;
use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Event\UserRegistered;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Account\Service\InstallationState;
use App\Module\Account\Service\RegistrationGate;
use App\Module\Account\Service\VerificationEmailSender;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\NullAuditActorProvider;
use App\Module\Billing\Entity\SubscriptionKind;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Billing\Service\TrialProvisioner;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\InstalledInstance;
use App\Tests\Support\RecordingAuditor;
use App\Tests\Support\RecordingLogger;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

final class RegisterUserHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RegisterUserHandler $handler;
    private WaitlistEntryRepository $entries;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $entries = $container->get(WaitlistEntryRepository::class);
        self::assertInstanceOf(WaitlistEntryRepository::class, $entries);
        $this->entries = $entries;
        $handler = $container->get(RegisterUserHandler::class);
        self::assertInstanceOf(RegisterUserHandler::class, $handler);
        $this->handler = $handler;

        // Sign-up refuses to create the first account on an instance; these
        // tests all register into an instance that is already installed.
        InstalledInstance::ensure(self::getContainer());
    }

    /**
     * Successful registration was audited nowhere before this: account creation
     * was invisible to the trail. The social branch writes the same operation
     * and separates itself with `provider`.
     */
    public function test_a_password_registration_is_recorded_with_a_null_provider(): void
    {
        $audit = new RecordingAuditor(new NullAuditActorProvider());
        $handler = $this->handlerWith($this->createStub(EventDispatcherInterface::class), $audit->auditor);

        $user = $handler(new RegisterUserCommand(
            email: 'registered-audit@example.com',
            fullName: 'Registered Audit',
            plainPassword: 'SecurePassword1!',
        ));

        $record = $audit->record('account.registered');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertSame(['userId' => (string) $user->id, 'provider' => null], $record->context);
        self::assertNotNull($record->subject);
        self::assertSame('user', $record->subject->type);
        self::assertSame((string) $user->id, $record->subject->id);

        self::assertContains('account.registered', $audit->domainLogLines());
        self::assertSame([], $audit->securityLogLines());
    }

    /**
     * The listener's exception is the one thing the record drops, so the
     * handler keeps a logger for it and nothing else.
     */
    public function test_only_the_listener_failure_is_logged_beside_the_auditor(): void
    {
        $audit = new RecordingAuditor(new NullAuditActorProvider());
        $logger = new RecordingLogger();
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())->method('dispatch')
            ->willThrowException(new \RuntimeException('listener exploded'));

        $this->handlerWith($dispatcher, $audit->auditor, $logger)(new RegisterUserCommand(
            email: 'listener-failed-split@example.com',
            fullName: 'Listener Failed Split',
            plainPassword: 'SecurePassword1!',
        ));

        DirectLogging::assertDiagnosticsLoggedBeside($audit, $logger, 'account.registration_listener_failed', ['error']);
        DirectLogging::assertOperationNotLoggedBy($audit, $logger, 'account.registered');
        self::assertSame('listener exploded', $logger->records[0]['context']['error'] ?? null);
    }

    /**
     * A listener that throws leaves the account created and its follow-up work
     * undone, which the trail says without repeating the listener's exception.
     */
    public function test_a_listener_that_throws_records_the_failure(): void
    {
        $audit = new RecordingAuditor(new NullAuditActorProvider());
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())->method('dispatch')
            ->willThrowException(new \RuntimeException('listener exploded'));

        $user = $this->handlerWith($dispatcher, $audit->auditor)(new RegisterUserCommand(
            email: 'listener-failed@example.com',
            fullName: 'Listener Failed',
            plainPassword: 'SecurePassword1!',
        ));

        self::assertSame(
            ['account.registered', 'account.registration_listener_failed'],
            $audit->operations(),
        );

        $record = $audit->record('account.registration_listener_failed');
        self::assertSame(AuditOutcome::Failed, $record->outcome);
        self::assertSame(['userId' => (string) $user->id], $record->context);
        self::assertNotNull($record->subject);
        self::assertSame('user', $record->subject->type);
        self::assertStringNotContainsString(
            'listener exploded',
            json_encode($audit->domainChannel->records, \JSON_THROW_ON_ERROR),
        );
    }

    /** A refused registration creates nothing, so it states nothing. */
    public function test_a_registration_refused_by_the_gate_records_nothing(): void
    {
        $this->closeRegistration();
        $audit = new RecordingAuditor(new NullAuditActorProvider());
        $handler = $this->handlerWith($this->neverDispatches(), $audit->auditor);

        try {
            $handler(new RegisterUserCommand(
                email: 'refused-audit@example.com',
                fullName: 'Refused Audit',
                plainPassword: 'SecurePassword1!',
            ));
            $this->fail('Expected DomainErrors to be thrown.');
        } catch (DomainErrors) {
        }

        self::assertSame([], $audit->records('account.registered'));
    }

    public function test_concurrent_duplicate_registration_surfaces_domain_error_not_500(): void
    {
        // Uses hand-stubbed collaborators (not the booted container) because
        // this exercises a flush()-level race that the real EM/DB cannot
        // reproduce in a single dama-wrapped test transaction.
        $users = $this->createStub(UserRepository::class);
        $users->method('findOneByEmail')->willReturn(null);
        $users->method('countHumans')->willReturn(1); // installation complete

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(fn (callable $func) => $func());
        $em->method('getConnection')->willReturn($this->em->getConnection());
        $em->method('flush')->willThrowException(
            new UniqueConstraintViolationException(
                PdoDriverException::new(new \PDOException('duplicate key value violates unique constraint')),
                null,
            ),
        );

        $passwordHasher = $this->createStub(UserPasswordHasherInterface::class);
        $passwordHasher->method('hashPassword')->willReturn('hashed');

        $flags = $this->createStub(FeatureFlagService::class);
        $flags->method('getIntValue')->willReturn(0); // unlimited — gate stays open
        $flags->method('isEnabled')->willReturn(true); // registration switched on
        $gate = new RegistrationGate($flags, $users, new InstallationState($users));

        $handler = new RegisterUserHandler(
            users: $users,
            em: $em,
            passwordHasher: $passwordHasher,
            verificationEmailSender: $this->createStub(VerificationEmailSender::class),
            registrationGate: $gate,
            waitlistEntries: $this->createStub(WaitlistEntryRepository::class),
            eventDispatcher: $this->neverDispatches(),
            logger: new RecordingLogger(),
            auditor: new RecordingAuditor(new NullAuditActorProvider())->auditor,
            termsVersion: $this->currentTermsVersion(),
        );

        try {
            $handler(new RegisterUserCommand(
                email: 'race@example.com',
                fullName: 'Riley Chen',
                plainPassword: 'SecurePassword1!',
            ));
            $this->fail('Expected DomainErrors to be thrown.');
        } catch (DomainErrors $e) {
            $this->assertSame(['email' => 'account.registration.error.email_duplicate'], $e->errors);
        }
    }

    public function test_password_registration_records_acceptance_of_the_current_terms(): void
    {
        // The form asserts IsTrue on agreeTerms, so reaching this handler is
        // itself the consent — and recording it is what keeps the OAuth-only
        // terms gate from firing for password registrants.
        $user = ($this->handler)($this->makeCommand(email: 'terms-recorded@example.com'));

        $this->assertNotNull($user->termsAcceptedAt);
        $this->assertSame($this->currentTermsVersion(), $user->termsVersion);
    }

    public function test_registration_open_with_a_mismatched_email_leaves_the_invite_unconverted(): void
    {
        // The token is a capacity voucher for the address it was issued to — a
        // forwarded/leaked link registering a different address must not
        // consume someone else's invite, open gate or not.
        $entry = new WaitlistEntry('unused-invite@example.com');
        $token = $entry->issueInviteToken();
        $this->em->persist($entry);
        $this->em->flush();

        ($this->handler)($this->makeCommand(email: 'open-gate@example.com', inviteToken: $token));

        $this->em->clear();
        $reloaded = $this->entries->findOneByEmail('unused-invite@example.com');
        $this->assertNotNull($reloaded);
        $this->assertNull($reloaded->convertedAt);
    }

    public function test_registration_open_with_a_matching_invite_still_consumes_it(): void
    {
        // Consuming the token even though the gate didn't need it prevents the
        // token being replayed later as a live capacity-bypass credential if
        // the gate closes again before the (still-valid) token expires.
        $entry = new WaitlistEntry('matching-open-gate@example.com');
        $token = $entry->issueInviteToken();
        $this->em->persist($entry);
        $this->em->flush();

        ($this->handler)($this->makeCommand(email: 'matching-open-gate@example.com', inviteToken: $token));

        $this->em->clear();
        $reloaded = $this->entries->findOneByEmail('matching-open-gate@example.com');
        $this->assertNotNull($reloaded?->convertedAt);
    }

    public function test_registration_open_without_a_token_still_converts_a_matching_waitlist_row(): void
    {
        // The person joined the waitlist earlier, then the cap reopened and
        // they registered normally without ever using an invite link — their
        // waitlist row must not linger as "waiting" once the account exists.
        $entry = new WaitlistEntry('joined-earlier@example.com');
        $this->em->persist($entry);
        $this->em->flush();

        ($this->handler)($this->makeCommand(email: 'joined-earlier@example.com'));

        $this->em->clear();
        $reloaded = $this->entries->findOneByEmail('joined-earlier@example.com');
        $this->assertNotNull($reloaded?->convertedAt);
    }

    public function test_registration_closed_with_a_valid_but_mismatched_email_token_is_rejected(): void
    {
        $this->closeRegistration();
        $entry = new WaitlistEntry('invited-someone-else@example.com');
        $token = $entry->issueInviteToken();
        $this->em->persist($entry);
        $this->em->flush();

        $this->expectException(DomainErrors::class);

        try {
            ($this->handler)($this->makeCommand(email: 'attacker-different-email@example.com', inviteToken: $token));
        } finally {
            $this->em->clear();
            $reloaded = $this->entries->findOneByEmail('invited-someone-else@example.com');
            $this->assertNotNull($reloaded);
            $this->assertNull($reloaded->convertedAt);
        }
    }

    public function test_registration_closed_without_invite_is_rejected(): void
    {
        $this->closeRegistration();

        $this->expectException(DomainErrors::class);

        ($this->handler)($this->makeCommand(email: 'late@example.com'));
    }

    public function test_registration_closed_with_wrong_token_is_rejected(): void
    {
        $this->closeRegistration();

        $this->expectException(DomainErrors::class);

        ($this->handler)($this->makeCommand(email: 'wrong-token@example.com', inviteToken: 'not-a-real-token'));
    }

    public function test_registration_closed_with_expired_token_is_rejected(): void
    {
        $this->closeRegistration();
        $entry = new WaitlistEntry('expired@example.com');
        $token = $entry->issueInviteToken(expiresAt: new \DateTimeImmutable('-1 minute'));
        $this->em->persist($entry);
        $this->em->flush();

        $this->expectException(DomainErrors::class);

        ($this->handler)($this->makeCommand(email: 'expired-registrant@example.com', inviteToken: $token));
    }

    public function test_registration_closed_with_already_converted_token_is_rejected(): void
    {
        $this->closeRegistration();
        $entry = new WaitlistEntry('already-converted@example.com');
        $token = $entry->issueInviteToken();
        $entry->markConverted();
        $this->em->persist($entry);
        $this->em->flush();

        $this->expectException(DomainErrors::class);

        ($this->handler)($this->makeCommand(email: 'second-attempt@example.com', inviteToken: $token));
    }

    public function test_valid_invite_bypasses_closed_gate_and_converts_entry(): void
    {
        $this->closeRegistration();
        $entry = new WaitlistEntry('invited@example.com');
        $token = $entry->issueInviteToken();
        $this->em->persist($entry);
        $this->em->flush();

        $user = ($this->handler)($this->makeCommand(email: 'invited@example.com', inviteToken: $token));

        $this->assertSame('invited@example.com', $user->email);

        $this->em->clear();
        $fresh = $this->entries->findOneByEmail('invited@example.com');
        $this->assertNotNull($fresh?->convertedAt);
    }

    public function test_successful_registration_dispatches_user_registered_after_commit(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())->method('dispatch')
            ->with($this->callback(
                static fn (object $event): bool => $event instanceof UserRegistered && 'dispatch-me@example.com' === $event->user->email,
            ))
            ->willReturnArgument(0);

        $user = ($this->handlerWith($dispatcher))($this->makeCommand(email: 'dispatch-me@example.com'));

        $this->assertSame('dispatch-me@example.com', $user->email);
    }

    public function test_registration_provisions_a_trial_billing_profile(): void
    {
        // Uses the container handler (real dispatcher), so the Billing
        // listener actually runs — the trial clock starts at registration.
        $this->enableBilling();

        $user = ($this->handler)($this->makeCommand(email: 'trial-clock@example.com'));

        $profiles = self::getContainer()->get(BillingProfileRepository::class);
        self::assertInstanceOf(BillingProfileRepository::class, $profiles);
        $profile = $profiles->findOneByUser($user);
        $this->assertNotNull($profile);

        // No billing.trial_days flag row exists in the test DB → default applies.
        $trialEndsAt = $profile->latestSubscriptionOfKind(SubscriptionKind::Trial)?->endsAt;
        self::assertNotNull($trialEndsAt);
        $expected = new \DateTimeImmutable(sprintf('+%d days', TrialProvisioner::DEFAULT_TRIAL_DAYS));
        $this->assertGreaterThan($expected->modify('-1 hour'), $trialEndsAt);
        $this->assertLessThan($expected->modify('+1 hour'), $trialEndsAt);
    }

    /**
     * With billing off no profile is created, so the trial clock does not tick
     * for accounts nobody is charging. The test above is what proves this one
     * is not passing merely because registration failed.
     */
    public function test_registration_provisions_no_trial_while_billing_is_disabled(): void
    {
        $user = ($this->handler)($this->makeCommand(email: 'no-trial-clock@example.com'));

        $profiles = self::getContainer()->get(BillingProfileRepository::class);
        self::assertInstanceOf(BillingProfileRepository::class, $profiles);
        $this->assertNull($profiles->findOneByUser($user));
    }

    private function enableBilling(): void
    {
        $this->em->persist(new FeatureFlag(name: 'billing.enabled', type: FeatureFlagType::Bool, value: true));
        $this->em->flush();
    }

    public function test_duplicate_email_dispatches_no_user_registered_event(): void
    {
        ($this->handler)($this->makeCommand(email: 'dupe@example.com'));

        $this->expectException(DomainErrors::class);

        ($this->handlerWith($this->neverDispatches()))($this->makeCommand(email: 'dupe@example.com'));
    }

    public function test_closed_registration_dispatches_no_user_registered_event(): void
    {
        $this->closeRegistration();

        $this->expectException(DomainErrors::class);

        ($this->handlerWith($this->neverDispatches()))($this->makeCommand(email: 'capped@example.com'));
    }

    public function test_a_failing_post_commit_listener_does_not_fail_the_registration(): void
    {
        // The account has committed by dispatch time; a listener blowing up
        // (e.g. trial provisioning hitting a transient DB error) must not turn
        // the created registration into a 500 — a retry would only dead-end on
        // "email already taken".
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException(new \RuntimeException('provisioning down'));

        $user = ($this->handlerWith($dispatcher))($this->makeCommand(email: 'listener-fail@example.com'));

        self::assertNotNull($user->id);
    }

    private function currentTermsVersion(): string
    {
        $version = self::getContainer()->getParameter('app.terms.version');
        self::assertIsString($version);

        return $version;
    }

    /** Container-wired collaborators with only the dispatcher swapped out. */
    private function handlerWith(EventDispatcherInterface $dispatcher, ?Auditor $auditor = null, ?RecordingLogger $logger = null): RegisterUserHandler
    {
        $container = self::getContainer();
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);
        $verificationEmailSender = $container->get(VerificationEmailSender::class);
        self::assertInstanceOf(VerificationEmailSender::class, $verificationEmailSender);
        $gate = $container->get(RegistrationGate::class);
        self::assertInstanceOf(RegistrationGate::class, $gate);

        return new RegisterUserHandler(
            users: $users,
            em: $this->em,
            passwordHasher: $passwordHasher,
            verificationEmailSender: $verificationEmailSender,
            registrationGate: $gate,
            waitlistEntries: $this->entries,
            eventDispatcher: $dispatcher,
            logger: $logger ?? new RecordingLogger(),
            auditor: $auditor ?? new RecordingAuditor(new NullAuditActorProvider())->auditor,
            termsVersion: $this->currentTermsVersion(),
        );
    }

    private function neverDispatches(): EventDispatcherInterface
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatch');

        return $dispatcher;
    }

    private function closeRegistration(): void
    {
        $container = self::getContainer();
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        $this->em->persist(new \App\Module\Account\Entity\User(
            fullName: 'Gate Filler',
            email: 'gate-filler-'.bin2hex(random_bytes(4)).'@example.com',
            password: 'x',
        ));
        $this->em->flush();

        $flag = new FeatureFlag(name: RegistrationGate::CAP_FLAG, type: FeatureFlagType::Int, value: $users->countActive());
        $this->em->persist($flag);
        $this->em->flush();
    }

    /** @param non-empty-string $email */
    private function makeCommand(string $email, ?string $inviteToken = null): RegisterUserCommand
    {
        return new RegisterUserCommand(
            email: $email,
            fullName: 'Riley Chen',
            plainPassword: 'SecurePassword1!',
            inviteToken: $inviteToken,
        );
    }
}
