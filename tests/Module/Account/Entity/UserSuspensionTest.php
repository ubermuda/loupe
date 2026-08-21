<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Entity;

use App\Module\Account\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserSuspensionTest extends TestCase
{
    public function test_a_new_user_is_not_suspended(): void
    {
        $user = new User(fullName: 'Test User', email: 'not-suspended@example.com');

        self::assertFalse($user->isSuspended());
        self::assertNull($user->suspendedReason);
        self::assertNull($user->suspendedBy);
    }

    public function test_setting_suspended_at_suspends_the_user(): void
    {
        $user = new User(fullName: 'Test User', email: 'suspended@example.com');
        $user->suspendedAt = new \DateTimeImmutable();

        self::assertTrue($user->isSuspended());
    }
}
