<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Entity\BillingStatus;
use App\Module\Billing\Entity\Subscription;
use App\Module\Billing\Entity\SubscriptionKind;

/**
 * Builds billing profiles and the grants on them, in memory. The database-backed
 * counterpart is BillingScenario, which persists what this class assembles.
 */
final readonly class BillingGrants
{
    public static function profileWithTrial(User $user, \DateTimeImmutable $trialEndsAt): BillingProfile
    {
        $profile = new BillingProfile($user);
        self::trial($profile, $trialEndsAt);

        return $profile;
    }

    public static function trial(BillingProfile $profile, \DateTimeImmutable $endsAt): Subscription
    {
        return new Subscription($profile, SubscriptionKind::Trial, $endsAt->modify('-14 days'), $endsAt);
    }

    public static function stripe(
        BillingProfile $profile,
        BillingStatus $status,
        ?\DateTimeImmutable $endsAt,
        string $stripeSubscriptionId = 'sub_test',
        ?\DateTimeImmutable $startsAt = null,
    ): Subscription {
        $subscription = new Subscription($profile, SubscriptionKind::Stripe, $startsAt ?? new \DateTimeImmutable('-1 hour'), $endsAt);
        $subscription->stripeStatus = $status;
        $subscription->stripeSubscriptionId = $stripeSubscriptionId;

        return $subscription;
    }

    public static function comp(BillingProfile $profile, ?\DateTimeImmutable $endsAt = null): Subscription
    {
        return new Subscription($profile, SubscriptionKind::Comp, new \DateTimeImmutable('-1 day'), $endsAt);
    }
}
