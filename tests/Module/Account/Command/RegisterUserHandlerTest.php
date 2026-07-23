<?php

namespace App\Tests\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Command\RegisterUserCommand;
use App\Module\Account\Command\RegisterUserHandler;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\VerificationEmailSender;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RegisterUserHandlerTest extends TestCase
{
    public function test_concurrent_duplicate_registration_surfaces_domain_error_not_500(): void
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findOneByEmail')->willReturn(null);
        $users->method('findOneByUsername')->willReturn(null);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('flush')->willThrowException(
            new UniqueConstraintViolationException(
                PdoDriverException::new(new \PDOException('duplicate key value violates unique constraint')),
                null,
            ),
        );

        $passwordHasher = $this->createStub(UserPasswordHasherInterface::class);
        $passwordHasher->method('hashPassword')->willReturn('hashed');

        $handler = new RegisterUserHandler(
            users: $users,
            em: $em,
            passwordHasher: $passwordHasher,
            verificationEmailSender: $this->createStub(VerificationEmailSender::class),
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
}
