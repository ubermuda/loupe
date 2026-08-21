<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Security;

use App\Module\Account\Entity\User;
use App\Module\Account\Security\AccountStatusChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

final class AccountStatusCheckerTest extends TestCase
{
    public function test_post_auth_rejects_a_suspended_user(): void
    {
        $user = $this->user();
        $user->suspendedAt = new \DateTimeImmutable();
        $user->suspendedReason = 'Spamming reviewers.';

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessage('Spamming reviewers.');

        new AccountStatusChecker()->checkPostAuth($user);
    }

    public function test_post_auth_allows_an_active_user(): void
    {
        new AccountStatusChecker()->checkPostAuth($this->user());

        $this->expectNotToPerformAssertions();
    }

    /** Pre-auth runs before the credential is accepted, so rejecting there would leak the suspension to anyone who can name the account. */
    public function test_pre_auth_never_rejects(): void
    {
        $user = $this->user();
        $user->suspendedAt = new \DateTimeImmutable();
        $user->suspendedReason = 'Spamming reviewers.';

        new AccountStatusChecker()->checkPreAuth($user);

        $this->expectNotToPerformAssertions();
    }

    private function user(): User
    {
        return new User(fullName: 'Riley Chen', email: 'riley@example.com', password: 'x');
    }
}
