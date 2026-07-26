<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Command\CreateInstallAdminCommand;
use App\Module\Account\Command\CreateInstallAdminHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CreateInstallAdminHandlerTest extends KernelTestCase
{
    public function test_creates_unverified_admin_with_hashed_password(): void
    {
        $handler = self::getContainer()->get(CreateInstallAdminHandler::class);

        $user = $handler(new CreateInstallAdminCommand(
            username: 'admin',
            fullName: 'The Admin',
            email: 'admin@example.com',
            plainPassword: 'a-strong-password',
        ));

        self::assertSame(['ROLE_ADMIN'], $user->roles);
        self::assertNull($user->emailVerifiedAt);
        self::assertNotSame('a-strong-password', $user->password);
        self::assertContains('ROLE_ADMIN', $user->getRoles());
        self::assertContains('ROLE_USER', $user->getRoles());
    }

    public function test_sends_verification_email(): void
    {
        $handler = self::getContainer()->get(CreateInstallAdminHandler::class);
        $handler(new CreateInstallAdminCommand('admin', 'The Admin', 'admin@example.com', 'a-strong-password'));

        $this->assertQueuedEmailCount(1);
    }

    public function test_throws_once_any_user_exists(): void
    {
        $handler = self::getContainer()->get(CreateInstallAdminHandler::class);
        $handler(new CreateInstallAdminCommand('admin', 'The Admin', 'admin@example.com', 'a-strong-password'));

        $this->expectException(DomainErrors::class);
        $handler(new CreateInstallAdminCommand('admin2', 'Second Admin', 'admin2@example.com', 'a-strong-password'));
    }
}
