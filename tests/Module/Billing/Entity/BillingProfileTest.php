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
        return new User(username: 'alice', fullName: 'Alice A', email: 'alice@example.com', password: 'irrelevant');
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

    public function test_past_due_and_canceled_are_not_current_after_trial(): void
    {
        $profile = new BillingProfile($this->user(), trialEndsAt: new \DateTimeImmutable('-1 day'));

        foreach ([BillingStatus::PastDue, BillingStatus::Canceled] as $status) {
            $profile->status = $status;
            self::assertFalse($profile->isCurrent(new \DateTimeImmutable()), $status->value);
        }
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
