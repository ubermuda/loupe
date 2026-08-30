<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Entity;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Entity\BillingStatus;
use App\Module\Billing\Entity\SubscriptionKind;
use App\Tests\Support\BillingGrants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BillingProfileTest extends TestCase
{
    private function profile(): BillingProfile
    {
        return new BillingProfile(new User(fullName: 'Alice A', email: 'alice@example.com', password: 'irrelevant'));
    }

    public function test_a_profile_with_no_grant_allows_nothing(): void
    {
        self::assertFalse($this->profile()->hasCurrentSubscription(new \DateTimeImmutable()));
    }

    public function test_any_current_grant_allows_access(): void
    {
        $profile = $this->profile();
        BillingGrants::trial($profile, new \DateTimeImmutable('-30 days'));
        BillingGrants::comp($profile);

        self::assertTrue($profile->hasCurrentSubscription(new \DateTimeImmutable()));
    }

    /**
     * The double-billing bug this model exists to make impossible: a comped
     * subscriber reported as having no subscription would be offered Checkout
     * and billed a second time.
     */
    public function test_a_comp_never_hides_a_live_stripe_subscription(): void
    {
        $profile = $this->profile();
        BillingGrants::stripe($profile, BillingStatus::Active, new \DateTimeImmutable('+30 days'));
        BillingGrants::comp($profile);

        self::assertTrue($profile->hasLiveSubscription());
        self::assertTrue($profile->hasCurrentSubscription(new \DateTimeImmutable()));
    }

    public function test_a_comp_alone_is_not_a_live_stripe_subscription(): void
    {
        $profile = $this->profile();
        BillingGrants::comp($profile);

        self::assertFalse($profile->hasLiveSubscription());
    }

    #[DataProvider('liveStatuses')]
    public function test_stripe_grants_stripe_still_holds_are_live(BillingStatus $status, bool $expected): void
    {
        $profile = $this->profile();
        BillingGrants::stripe($profile, $status, new \DateTimeImmutable('+30 days'));

        self::assertSame($expected, $profile->hasLiveSubscription());
    }

    /** @return iterable<string, array{BillingStatus, bool}> */
    public static function liveStatuses(): iterable
    {
        yield 'active' => [BillingStatus::Active, true];

        yield 'past due is still a subscription to manage' => [BillingStatus::PastDue, true];

        yield 'canceled' => [BillingStatus::Canceled, false];
    }

    public function test_the_latest_grant_of_a_kind_is_the_most_recently_created(): void
    {
        $profile = $this->profile();
        $old = BillingGrants::stripe($profile, BillingStatus::Canceled, new \DateTimeImmutable('-1 day'), 'sub_old');
        $old->createdAt = new \DateTimeImmutable('-60 days');
        $new = BillingGrants::stripe($profile, BillingStatus::Active, new \DateTimeImmutable('+30 days'), 'sub_new');

        self::assertSame($new, $profile->latestSubscriptionOfKind(SubscriptionKind::Stripe));
        self::assertNull($profile->latestSubscriptionOfKind(SubscriptionKind::Comp));
    }

    #[DataProvider('stripeStatuses')]
    public function test_stripe_status_mapping(string $stripeStatus, BillingStatus $expected): void
    {
        self::assertSame($expected, BillingStatus::fromStripeStatus($stripeStatus));
    }

    /** @return iterable<string, array{string, BillingStatus}> */
    public static function stripeStatuses(): iterable
    {
        yield 'active' => ['active', BillingStatus::Active];
        yield 'stripe-side trial still means subscribed' => ['trialing', BillingStatus::Active];
        yield 'past_due' => ['past_due', BillingStatus::PastDue];
        yield 'unpaid' => ['unpaid', BillingStatus::PastDue];
        yield 'canceled' => ['canceled', BillingStatus::Canceled];
        yield 'incomplete paywalls' => ['incomplete', BillingStatus::Canceled];
        yield 'unknown future status paywalls' => ['something_new', BillingStatus::Canceled];
    }
}
