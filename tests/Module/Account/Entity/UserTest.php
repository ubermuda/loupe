<?php

namespace App\Tests\Module\Account\Entity;

use App\Module\Account\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    private function makeUser(): User
    {
        return new User(fullName: 'Test User', email: 'test@example.com', password: 'hashed');
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
        $user = new User(fullName: 'Octo Cat', email: 'octo@example.com');

        $this->assertFalse($user->hasUsablePassword());
    }

    public function test_has_usable_password_is_true_when_password_set(): void
    {
        $this->assertTrue($this->makeUser()->hasUsablePassword());
    }

    public function test_account_deletion_token_round_trip(): void
    {
        $user = $this->makeUser();

        $token = $user->generateAccountDeletionToken();

        $this->assertSame(64, strlen($token));
        $this->assertTrue($user->isAccountDeletionTokenValid($token));
        $this->assertFalse($user->isAccountDeletionTokenValid('wrong-token'));
    }

    public function test_account_deletion_token_invalid_when_never_generated(): void
    {
        $this->assertFalse($this->makeUser()->isAccountDeletionTokenValid('anything'));
    }

    public function test_cleared_account_deletion_token_no_longer_validates(): void
    {
        $user = $this->makeUser();
        $token = $user->generateAccountDeletionToken();

        $user->clearAccountDeletionToken();

        $this->assertFalse($user->isAccountDeletionTokenValid($token));
    }

    public function test_only_the_hash_is_stored_never_the_raw_token(): void
    {
        $user = $this->makeUser();
        $token = $user->generateAccountDeletionToken();

        $ref = new \ReflectionProperty(User::class, 'accountDeletionTokenHash');
        $stored = $ref->getValue($user);

        $this->assertNotSame($token, $stored);
        $this->assertSame(hash('sha256', $token), $stored);
    }

    public function test_regenerating_invalidates_the_previous_token(): void
    {
        $user = $this->makeUser();
        $first = $user->generateAccountDeletionToken();
        $second = $user->generateAccountDeletionToken();

        $this->assertFalse($user->isAccountDeletionTokenValid($first));
        $this->assertTrue($user->isAccountDeletionTokenValid($second));
    }

    public function test_account_deletion_token_expires(): void
    {
        $user = $this->makeUser();
        $token = $user->generateAccountDeletionToken();

        $ref = new \ReflectionProperty(User::class, 'accountDeletionTokenExpiresAt');
        $ref->setValue($user, new \DateTimeImmutable('-1 minute'));

        $this->assertFalse($user->isAccountDeletionTokenValid($token));
    }

    public function test_has_active_account_deletion_token(): void
    {
        $user = $this->makeUser();

        $this->assertFalse($user->hasActiveAccountDeletionToken());

        $user->generateAccountDeletionToken();
        $this->assertTrue($user->hasActiveAccountDeletionToken());

        $user->clearAccountDeletionToken();
        $this->assertFalse($user->hasActiveAccountDeletionToken());
    }

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
