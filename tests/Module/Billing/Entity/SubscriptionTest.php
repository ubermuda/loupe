<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Entity;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Entity\BillingStatus;
use App\Module\Billing\Entity\Subscription;
use App\Module\Billing\Entity\SubscriptionKind;
use App\Tests\Support\BillingGrants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubscriptionTest extends TestCase
{
    private function profile(): BillingProfile
    {
        return new BillingProfile(new User(fullName: 'Alice A', email: 'alice@example.com', password: 'irrelevant'));
    }

    public function test_a_grant_is_current_between_its_start_and_its_end(): void
    {
        $subscription = new Subscription($this->profile(), SubscriptionKind::Trial, new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+1 day'));

        self::assertTrue($subscription->isCurrent(new \DateTimeImmutable()));
        self::assertFalse($subscription->isCurrent(new \DateTimeImmutable('+2 days')));
        self::assertFalse($subscription->isCurrent(new \DateTimeImmutable('-2 days')));
    }

    public function test_a_grant_with_no_end_never_stops(): void
    {
        $subscription = new Subscription($this->profile(), SubscriptionKind::Comp, new \DateTimeImmutable('-1 day'));

        self::assertTrue($subscription->isCurrent(new \DateTimeImmutable()));
        self::assertTrue($subscription->isCurrent(new \DateTimeImmutable('+10 years')));
    }

    public function test_the_end_instant_itself_is_already_over(): void
    {
        $endsAt = new \DateTimeImmutable('2026-07-25 12:00:00');
        $subscription = new Subscription($this->profile(), SubscriptionKind::Trial, $endsAt->modify('-14 days'), $endsAt);

        self::assertFalse($subscription->isCurrent($endsAt));
        self::assertTrue($subscription->isCurrent($endsAt->modify('-1 second')));
    }

    #[DataProvider('kinds')]
    public function test_a_second_current_grant_of_the_same_kind_is_refused(SubscriptionKind $kind): void
    {
        $profile = $this->profile();
        new Subscription($profile, $kind, new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+1 day'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(sprintf('already holds a current %s subscription', $kind->value));

        new Subscription($profile, $kind, new \DateTimeImmutable(), new \DateTimeImmutable('+30 days'));
    }

    /** @return iterable<string, array{SubscriptionKind}> */
    public static function kinds(): iterable
    {
        yield 'trial' => [SubscriptionKind::Trial];

        yield 'stripe' => [SubscriptionKind::Stripe];

        yield 'comp' => [SubscriptionKind::Comp];
    }

    public function test_grants_of_different_kinds_run_side_by_side(): void
    {
        $profile = $this->profile();
        BillingGrants::trial($profile, new \DateTimeImmutable('+3 days'));
        BillingGrants::stripe($profile, BillingStatus::Active, new \DateTimeImmutable('+30 days'));
        BillingGrants::comp($profile);

        self::assertCount(3, $profile->subscriptions);
    }

    public function test_a_new_grant_replaces_one_that_already_ended(): void
    {
        $profile = $this->profile();
        BillingGrants::stripe($profile, BillingStatus::Canceled, new \DateTimeImmutable('-1 day'), 'sub_old');
        BillingGrants::stripe($profile, BillingStatus::Active, new \DateTimeImmutable('+30 days'), 'sub_new');

        self::assertCount(2, $profile->subscriptions);
        self::assertSame('sub_new', $profile->currentSubscriptionOfKind(SubscriptionKind::Stripe, new \DateTimeImmutable())?->stripeSubscriptionId);
    }
}
