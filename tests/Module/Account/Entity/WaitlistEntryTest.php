<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Entity;

use App\Module\Account\Entity\WaitlistEntry;
use PHPUnit\Framework\TestCase;

final class WaitlistEntryTest extends TestCase
{
    public function test_email_is_lowercased(): void
    {
        self::assertSame('who@example.com', new WaitlistEntry('WHO@Example.COM')->email);
    }

    public function test_issue_invite_token_stores_hash_and_expiry(): void
    {
        $entry = new WaitlistEntry('a@example.com');
        $token = $entry->issueInviteToken();

        self::assertNotNull($entry->invitedAt);
        self::assertNotNull($entry->inviteExpiresAt);
        self::assertTrue($entry->isInviteTokenValid($token));
        self::assertFalse($entry->isInviteTokenValid('wrong-token'));
    }

    public function test_expired_invite_token_is_invalid(): void
    {
        $entry = new WaitlistEntry('a@example.com');
        $token = $entry->issueInviteToken(expiresAt: new \DateTimeImmutable('-1 minute'));

        self::assertFalse($entry->isInviteTokenValid($token));
    }

    public function test_converted_entry_rejects_its_token(): void
    {
        $entry = new WaitlistEntry('a@example.com');
        $token = $entry->issueInviteToken();
        $entry->markConverted();

        self::assertNotNull($entry->convertedAt);
        self::assertFalse($entry->isInviteTokenValid($token));
    }

    public function test_is_invited_reflects_state(): void
    {
        $entry = new WaitlistEntry('a@example.com');
        self::assertFalse($entry->isInvited());
        $entry->issueInviteToken();
        self::assertTrue($entry->isInvited());
    }
}
