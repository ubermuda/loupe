<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command;

use App\Module\Account\Command\CreateAdminUserCommand;
use App\Module\Account\Command\CreateAdminUserHandler;
use App\Module\Account\Entity\User;
use App\Module\Account\Service\RegistrationGate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;
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

    private function countAgentRows(): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM users WHERE id = :id',
            ['id' => User::AGENT_ID],
        );
    }

    public function test_it_restores_a_missing_agent_account(): void
    {
        $this->em->getConnection()->delete('users', ['id' => User::AGENT_ID]);

        ($this->handler)(new CreateAdminUserCommand(
            email: 'agentrepair@example.com',
            plainPassword: 'SecurePassword1!',
        ));

        self::assertSame(1, $this->countAgentRows());
    }

    public function test_it_restores_a_missing_agent_account_for_an_existing_administrator(): void
    {
        // The repair case that matters: an operator re-running this command to
        // fix a missing agent row supplies the email they already registered
        // with, which takes the early existing-user return. Installing only
        // alongside a new admin would make the documented recovery unable to
        // perform the repair it exists for.
        ($this->handler)(new CreateAdminUserCommand(
            email: 'agentrepair2@example.com',
            plainPassword: 'SecurePassword1!',
        ));
        $this->em->getConnection()->delete('users', ['id' => User::AGENT_ID]);
        self::assertSame(0, $this->countAgentRows());

        $result = ($this->handler)(new CreateAdminUserCommand(
            email: 'agentrepair2@example.com',
            plainPassword: 'SecurePassword1!',
        ));

        self::assertFalse($result->created);
        self::assertSame(1, $this->countAgentRows());
    }

    public function test_an_omitted_full_name_is_derived_from_the_email(): void
    {
        $result = ($this->handler)(new CreateAdminUserCommand(
            email: 'ada.lovelace@example.com',
            plainPassword: 'SecurePassword1!',
        ));

        // Nothing asks the operator for a name here, and every account has one.
        self::assertSame('Ada Lovelace', $result->user->fullName);
    }

    public function test_a_given_full_name_is_kept_over_a_derived_one(): void
    {
        $result = ($this->handler)(new CreateAdminUserCommand(
            email: 'ada.lovelace@example.com',
            plainPassword: 'SecurePassword1!',
            fullName: 'Ada, Countess of Lovelace',
        ));

        self::assertSame('Ada, Countess of Lovelace', $result->user->fullName);
    }

    public function test_it_leaves_the_install_feature_flags_alone(): void
    {
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $this->em->persist(new FeatureFlag(name: RegistrationGate::ENABLED_FLAG, type: FeatureFlagType::Bool, value: false));
        $this->em->flush();

        ($this->handler)(new CreateAdminUserCommand(email: 'flags@example.com', plainPassword: 'SecurePassword1!'));

        // Creating an administrator is not an install: an operator adding one
        // to a configured instance must not have its flags reset underneath it.
        $indexed = $flags->findAllIndexed();
        self::assertSame([RegistrationGate::ENABLED_FLAG], array_keys($indexed));
        self::assertFalse($indexed[RegistrationGate::ENABLED_FLAG]->value);
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

        $user = new User(fullName: 'First Arrival', email: 'squatter@example.com');
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
}
