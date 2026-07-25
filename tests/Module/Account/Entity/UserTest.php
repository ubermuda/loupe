<?php

namespace App\Tests\Module\Account\Entity;

use App\Module\Account\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    private function makeUser(): User
    {
        return new User(username: 'testuser', fullName: 'Test User', email: 'test@example.com', password: 'hashed');
    }

    public function test_new_user_is_unverified(): void
    {
        $this->assertFalse($this->makeUser()->isVerified());
    }

    public function test_user_has_role_user(): void
    {
        $this->assertContains('ROLE_USER', $this->makeUser()->getRoles());
    }

    public function test_verified_after_email_verified_at_set(): void
    {
        $user = $this->makeUser();
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $this->assertTrue($user->isVerified());
    }

    public function test_has_usable_password_is_false_for_oauth_only_user(): void
    {
        $user = new User(username: 'octo', fullName: 'Octo Cat', email: 'octo@example.com');

        $this->assertFalse($user->hasUsablePassword());
    }

    public function test_has_usable_password_is_true_when_password_set(): void
    {
        $this->assertTrue($this->makeUser()->hasUsablePassword());
    }
}
