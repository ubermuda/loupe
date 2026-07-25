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

    public function test_needs_invite_is_true_for_a_never_invited_entry(): void
    {
        self::assertTrue(new WaitlistEntry('a@example.com')->needsInvite());
    }

    public function test_needs_invite_is_false_while_an_active_invite_is_outstanding(): void
    {
        $entry = new WaitlistEntry('a@example.com');
        $entry->issueInviteToken();

        self::assertFalse($entry->needsInvite());
    }

    public function test_needs_invite_is_true_once_the_invite_link_expires_unused(): void
    {
        $entry = new WaitlistEntry('a@example.com');
        $entry->issueInviteToken(expiresAt: new \DateTimeImmutable('-1 minute'));

        self::assertTrue($entry->needsInvite());
    }

    public function test_needs_invite_is_false_for_a_converted_entry_even_if_its_invite_expired(): void
    {
        $entry = new WaitlistEntry('a@example.com');
        $entry->issueInviteToken(expiresAt: new \DateTimeImmutable('-1 minute'));
        $entry->markConverted();

        self::assertFalse($entry->needsInvite());
    }
}
