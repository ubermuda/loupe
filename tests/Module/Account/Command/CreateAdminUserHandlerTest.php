<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Command\CreateAdminUserCommand;
use App\Module\Account\Command\CreateAdminUserHandler;
use App\Module\Account\Entity\User;
use App\Module\Account\Service\RegistrationGate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class CreateAdminUserHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CreateAdminUserHandler $handler;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $handler = self::getContainer()->get(CreateAdminUserHandler::class);
        self::assertInstanceOf(CreateAdminUserHandler::class, $handler);
        $this->handler = $handler;
    }

    public function test_it_creates_a_verified_administrator_on_an_empty_database(): void
    {
        $result = ($this->handler)(new CreateAdminUserCommand(
            email: 'Recovery@Example.com',
            plainPassword: 'SecurePassword1!',
        ));

        self::assertTrue($result->created);
        self::assertSame('recovery@example.com', $result->user->email);
        self::assertContains('ROLE_ADMIN', $result->user->getRoles());
        self::assertTrue($result->user->isVerified());
        // No verification email: the operator is recovering an instance whose
        // mail may be exactly what is broken.
        self::assertQueuedEmailCount(0);
    }

    public function test_it_seeds_the_install_flags_the_wizard_would_have(): void
    {
        ($this->handler)(new CreateAdminUserCommand(email: 'flags@example.com', plainPassword: 'SecurePassword1!'));

        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $indexed = $flags->findAllIndexed();

        self::assertArrayHasKey(RegistrationGate::CAP_FLAG, $indexed);
        self::assertArrayHasKey(RegistrationGate::ENABLED_FLAG, $indexed);
    }

    public function test_rerunning_against_a_verified_administrator_changes_nothing(): void
    {
        ($this->handler)(new CreateAdminUserCommand(email: 'twice@example.com', plainPassword: 'SecurePassword1!'));
        $this->em->clear();

        $second = ($this->handler)(new CreateAdminUserCommand(email: 'twice@example.com', plainPassword: 'SecurePassword1!'));

        self::assertFalse($second->created);
        self::assertFalse($second->promoted);
        self::assertFalse($second->verified);
    }

    public function test_an_existing_account_is_promoted_and_verified_but_keeps_its_password(): void
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $user = new User(username: 'squatter', fullName: 'First Arrival', email: 'squatter@example.com');
        $user->password = $hasher->hashPassword($user, 'TheirOwnPassword1!');
        $this->em->persist($user);
        $this->em->flush();
        $originalHash = $user->password;

        $result = ($this->handler)(new CreateAdminUserCommand(
            email: 'squatter@example.com',
            plainPassword: 'SomethingElse1!',
        ));

        self::assertFalse($result->created);
        self::assertTrue($result->promoted);
        self::assertTrue($result->verified);
        self::assertSame($originalHash, $result->user->password);
    }

    public function test_an_explicit_username_that_is_taken_is_rejected_rather_than_suffixed(): void
    {
        $existing = new User(username: 'wanted', fullName: 'Existing', email: 'existing@example.com');
        $existing->password = 'not-a-real-hash';
        $this->em->persist($existing);
        $this->em->flush();

        try {
            ($this->handler)(new CreateAdminUserCommand(
                email: 'newadmin@example.com',
                plainPassword: 'SecurePassword1!',
                username: 'wanted',
            ));
            self::fail('Expected DomainErrors to be thrown.');
        } catch (DomainErrors $e) {
            self::assertSame(['username' => 'account.console.error.username_taken'], $e->errors);
        }
    }

    public function test_a_derived_username_avoids_a_collision(): void
    {
        $existing = new User(username: 'ops', fullName: 'Existing', email: 'ops@elsewhere.test');
        $existing->password = 'not-a-real-hash';
        $this->em->persist($existing);
        $this->em->flush();

        $result = ($this->handler)(new CreateAdminUserCommand(email: 'ops@example.com', plainPassword: 'SecurePassword1!'));

        self::assertTrue($result->created);
        self::assertNotSame('ops', $result->user->username);
        self::assertStringStartsWith('ops', $result->user->username);
    }

    public function test_a_short_password_is_rejected(): void
    {
        try {
            ($this->handler)(new CreateAdminUserCommand(email: 'short@example.com', plainPassword: 'short'));
            self::fail('Expected DomainErrors to be thrown.');
        } catch (DomainErrors $e) {
            self::assertSame(['plainPassword' => 'account.console.error.password_too_short'], $e->errors);
        }
    }

    public function test_a_malformed_email_is_rejected(): void
    {
        try {
            ($this->handler)(new CreateAdminUserCommand(email: 'not-an-email', plainPassword: 'SecurePassword1!'));
            self::fail('Expected DomainErrors to be thrown.');
        } catch (DomainErrors $e) {
            self::assertSame(['email' => 'account.console.error.email_invalid'], $e->errors);
        }
    }
}
