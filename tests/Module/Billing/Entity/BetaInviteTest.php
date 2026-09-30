<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Entity;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BetaInvite;
use PHPUnit\Framework\TestCase;

final class BetaInviteTest extends TestCase
{
    public function test_issue_stores_only_the_hash_of_the_raw_token(): void
    {
        $creator = new User(fullName: 'Admin', email: 'admin@example.com');
        [$invite, $token] = BetaInvite::issue($creator, 'For Riley');

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        self::assertSame(hash('sha256', $token), $invite->tokenHash);
        self::assertNotSame($token, $invite->tokenHash);
        self::assertSame($creator, $invite->createdBy);
        self::assertSame('For Riley', $invite->note);
    }

    public function test_each_issue_draws_a_new_token(): void
    {
        [, $first] = BetaInvite::issue(null);
        [, $second] = BetaInvite::issue(null);

        self::assertNotSame($first, $second);
    }

    public function test_matches_only_its_own_token(): void
    {
        [$invite, $token] = BetaInvite::issue(null);

        self::assertTrue($invite->matches($token));
        self::assertFalse($invite->matches(strrev($token)));
        self::assertFalse($invite->matches(''));
    }

    public function test_a_new_invite_is_usable(): void
    {
        [$invite] = BetaInvite::issue(null);

        self::assertTrue($invite->isUsable());
        self::assertFalse($invite->isRedeemed());
        self::assertFalse($invite->isRevoked());
    }

    public function test_redeem_records_the_user_and_ends_usability(): void
    {
        [$invite] = BetaInvite::issue(null);
        $tester = new User(fullName: 'Tester', email: 'tester@example.com');

        $invite->redeem($tester);

        self::assertSame($tester, $invite->redeemedBy);
        self::assertNotNull($invite->redeemedAt);
        self::assertTrue($invite->isRedeemed());
        self::assertFalse($invite->isUsable());
    }

    public function test_a_redeemed_invite_cannot_be_redeemed_again(): void
    {
        [$invite] = BetaInvite::issue(null);
        $first = new User(fullName: 'First', email: 'first@example.com');
        $invite->redeem($first);

        $this->expectException(\LogicException::class);
        try {
            $invite->redeem(new User(fullName: 'Second', email: 'second@example.com'));
        } finally {
            self::assertSame($first, $invite->redeemedBy);
        }
    }

    public function test_a_revoked_invite_is_not_usable_and_cannot_be_redeemed(): void
    {
        [$invite] = BetaInvite::issue(null);
        $invite->revoke();

        self::assertTrue($invite->isRevoked());
        self::assertFalse($invite->isUsable());

        $this->expectException(\LogicException::class);
        $invite->redeem(new User(fullName: 'Tester', email: 'tester@example.com'));
    }

    public function test_revoke_keeps_the_first_revocation_date(): void
    {
        [$invite] = BetaInvite::issue(null);
        $invite->revoke();
        $first = $invite->revokedAt;

        $invite->revoke();

        self::assertSame($first, $invite->revokedAt);
    }

    public function test_a_redeemed_invite_cannot_be_revoked(): void
    {
        [$invite] = BetaInvite::issue(null);
        $invite->redeem(new User(fullName: 'Tester', email: 'tester@example.com'));

        $this->expectException(\LogicException::class);
        $invite->revoke();
    }
}
