<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Entity;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Entity\BillingStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BillingProfileTest extends TestCase
{
    private function user(): User
    {
        return new User(fullName: 'Alice A', email: 'alice@example.com', password: 'irrelevant');
    }

    public function test_trialing_profile_is_current_until_trial_ends(): void
    {
        $profile = new BillingProfile($this->user(), trialEndsAt: new \DateTimeImmutable('+3 days'));

        self::assertTrue($profile->isCurrent(new \DateTimeImmutable()));
        self::assertFalse($profile->isCurrent(new \DateTimeImmutable('+4 days')));
    }

    public function test_active_subscription_is_current_even_after_trial_end(): void
    {
        $profile = new BillingProfile($this->user(), trialEndsAt: new \DateTimeImmutable('-1 day'));
        $profile->status = BillingStatus::Active;

        self::assertTrue($profile->isCurrent(new \DateTimeImmutable()));
    }

    public function test_past_due_is_not_current_after_trial(): void
    {
        $profile = new BillingProfile($this->user(), trialEndsAt: new \DateTimeImmutable('-1 day'));
        $profile->status = BillingStatus::PastDue;

        self::assertFalse($profile->isCurrent(new \DateTimeImmutable()));
    }

    public function test_canceled_without_a_period_end_is_not_current(): void
    {
        $profile = new BillingProfile($this->user(), trialEndsAt: new \DateTimeImmutable('-1 day'));
        $profile->status = BillingStatus::Canceled;

        self::assertFalse($profile->isCurrent(new \DateTimeImmutable()));
    }

    public function test_canceled_with_a_lapsed_period_end_is_not_current(): void
    {
        $profile = new BillingProfile($this->user(), trialEndsAt: new \DateTimeImmutable('-30 days'));
        $profile->status = BillingStatus::Canceled;
        $profile->currentPeriodEnd = new \DateTimeImmutable('-1 day');

        self::assertFalse($profile->isCurrent(new \DateTimeImmutable()));
    }

    /**
     * A mid-period cancel: Stripe already fired `deleted`, but the customer
     * paid through `currentPeriodEnd`. `SyncStripeSubscriptionHandler` and the
     * trial sweep both keep the account enabled until that date lapses, so
     * the paywall must agree.
     */
    public function test_canceled_with_a_future_period_end_is_current(): void
    {
        $profile = new BillingProfile($this->user(), trialEndsAt: new \DateTimeImmutable('-30 days'));
        $profile->status = BillingStatus::Canceled;
        $profile->currentPeriodEnd = new \DateTimeImmutable('+5 days');
        $profile->lastStripeEventType = BillingProfile::SUBSCRIPTION_DELETED_EVENT_TYPE;

        self::assertTrue($profile->isCurrent(new \DateTimeImmutable()));
    }

    /**
     * BillingStatus::fromStripeStatus() also folds `incomplete`,
     * `incomplete_expired`, and any status Stripe adds later into `Canceled` —
     * none of those subscriptions ever went live, so a future
     * `currentPeriodEnd` on one of them must not grant access. Only a genuine
     * `customer.subscription.deleted` (the mid-period-cancel case above) may.
     */
    public function test_canceled_with_a_future_period_end_but_no_deletion_event_is_not_current(): void
    {
        $profile = new BillingProfile($this->user(), trialEndsAt: new \DateTimeImmutable('-30 days'));
        $profile->status = BillingStatus::Canceled;
        $profile->currentPeriodEnd = new \DateTimeImmutable('+5 days');
        $profile->lastStripeEventType = 'customer.subscription.updated';

        self::assertFalse($profile->isCurrent(new \DateTimeImmutable()));
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
