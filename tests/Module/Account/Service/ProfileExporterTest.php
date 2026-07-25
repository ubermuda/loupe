<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Service\ProfileExporter;
use PHPUnit\Framework\TestCase;

final class ProfileExporterTest extends TestCase
{
    public function test_exports_profile_fields_without_credentials(): void
    {
        $user = new User('alice', 'Alice A', 'alice@example.com', 'hashed-password');
        $data = new ProfileExporter()->export($user);

        self::assertSame('alice', $data['username']);
        self::assertSame('Alice A', $data['fullName']);
        self::assertSame('alice@example.com', $data['email']);
        self::assertArrayHasKey('createdAt', $data);
        self::assertArrayNotHasKey('password', $data);
        self::assertSame('profile.json', new ProfileExporter()->filename());
    }
}
