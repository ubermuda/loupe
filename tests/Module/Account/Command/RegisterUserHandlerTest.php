<?php

namespace App\Tests\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Command\RegisterUserCommand;
use App\Module\Account\Command\RegisterUserHandler;
use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Account\Service\RegistrationGate;
use App\Module\Account\Service\VerificationEmailSender;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
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
    }

    public function test_concurrent_duplicate_registration_surfaces_domain_error_not_500(): void
    {
        // Uses hand-stubbed collaborators (not the booted container) because
        // this exercises a flush()-level race that the real EM/DB cannot
        // reproduce in a single dama-wrapped test transaction.
        $users = $this->createStub(UserRepository::class);
        $users->method('findOneByEmail')->willReturn(null);
        $users->method('findOneByUsername')->willReturn(null);

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
        $gate = new RegistrationGate($flags, $users);

        $handler = new RegisterUserHandler(
            users: $users,
            em: $em,
            passwordHasher: $passwordHasher,
            verificationEmailSender: $this->createStub(VerificationEmailSender::class),
            registrationGate: $gate,
            waitlistEntries: $this->createStub(WaitlistEntryRepository::class),
        );

        try {
            $handler(new RegisterUserCommand(
                username: 'raceuser',
                fullName: 'Race User',
                email: 'race@example.com',
                plainPassword: 'SecurePassword1!',
            ));
            $this->fail('Expected DomainErrors to be thrown.');
        } catch (DomainErrors $e) {
            $this->assertSame(['email' => 'account.registration.error.email_duplicate'], $e->errors);
        }
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

    private function closeRegistration(): void
    {
        $container = self::getContainer();
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        $this->em->persist(new \App\Module\Account\Entity\User(
            username: 'gate-filler-'.bin2hex(random_bytes(4)),
            fullName: 'Gate Filler',
            email: 'gate-filler-'.bin2hex(random_bytes(4)).'@example.com',
            password: 'x',
        ));
        $this->em->flush();

        $flag = new FeatureFlag(name: RegistrationGate::CAP_FLAG, type: FeatureFlagType::Int, value: $users->countAll());
        $this->em->persist($flag);
        $this->em->flush();
    }

    /** @param non-empty-string $email */
    private function makeCommand(string $email, ?string $inviteToken = null): RegisterUserCommand
    {
        return new RegisterUserCommand(
            username: 'user-'.bin2hex(random_bytes(4)),
            fullName: 'Test User',
            email: $email,
            plainPassword: 'SecurePassword1!',
            inviteToken: $inviteToken,
        );
    }
}
