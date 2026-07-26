<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Service;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Entity\BillingStatus;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Billing\Service\PaywallGate;
use App\Module\Billing\Service\TrialProvisioner;
use App\Tests\Support\FeatureFlags;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * TrialProvisioner is final, so the gate is exercised against a real one whose
 * repository already holds the profile under test — no persistence happens.
 */
final class PaywallGateTest extends TestCase
{
    private function user(): User
    {
        return new User(username: 'gated', fullName: 'Gated User', email: 'gated@example.com', password: 'irrelevant');
    }

    private function gate(bool $billingEnabled, BillingProfile $profile): PaywallGate
    {
        $profiles = $this->createStub(BillingProfileRepository::class);
        $profiles->method('findOneByUser')->willReturn($profile);

        return new PaywallGate(
            FeatureFlags::service(['billing.enabled' => $billingEnabled]),
            new TrialProvisioner($profiles, FeatureFlags::service(), $this->createStub(EntityManagerInterface::class)),
        );
    }

    /** @param array<string, bool|int|string> $flags */
    private function gateWithUntouchedProfiles(array $flags): PaywallGate
    {
        $profiles = $this->createMock(BillingProfileRepository::class);
        $profiles->expects($this->never())->method('findOneByUser');

        return new PaywallGate(
            FeatureFlags::service($flags),
            new TrialProvisioner($profiles, FeatureFlags::service(), $this->createStub(EntityManagerInterface::class)),
        );
    }

    public function test_flag_off_lets_everyone_through_without_touching_billing_state(): void
    {
        self::assertTrue($this->gateWithUntouchedProfiles(['billing.enabled' => false])->allows($this->user()));
    }

    public function test_missing_flag_is_treated_as_off(): void
    {
        self::assertTrue($this->gateWithUntouchedProfiles([])->allows($this->user()));
    }

    public function test_running_trial_is_allowed(): void
    {
        $user = $this->user();

        self::assertTrue($this->gate(true, new BillingProfile($user, trialEndsAt: new \DateTimeImmutable('+5 days')))->allows($user));
    }

    public function test_expired_trial_without_subscription_is_blocked(): void
    {
        $user = $this->user();

        self::assertFalse($this->gate(true, new BillingProfile($user, trialEndsAt: new \DateTimeImmutable('-1 day')))->allows($user));
    }

    public function test_active_subscription_after_the_trial_is_allowed(): void
    {
        $user = $this->user();
        $profile = new BillingProfile($user, trialEndsAt: new \DateTimeImmutable('-30 days'));
        $profile->status = BillingStatus::Active;

        self::assertTrue($this->gate(true, $profile)->allows($user));
    }

    public function test_past_due_subscription_after_the_trial_is_blocked(): void
    {
        $user = $this->user();
        $profile = new BillingProfile($user, trialEndsAt: new \DateTimeImmutable('-30 days'));
        $profile->status = BillingStatus::PastDue;

        self::assertFalse($this->gate(true, $profile)->allows($user));
    }

    /**
     * A mid-period cancel: the customer already paid through `currentPeriodEnd`,
     * so the paywall must not lock them out before that date — see
     * BillingProfile::isCurrent().
     */
    public function test_canceled_subscription_with_a_future_period_end_is_allowed(): void
    {
        $user = $this->user();
        $profile = new BillingProfile($user, trialEndsAt: new \DateTimeImmutable('-30 days'));
        $profile->status = BillingStatus::Canceled;
        $profile->currentPeriodEnd = new \DateTimeImmutable('+5 days');

        self::assertTrue($this->gate(true, $profile)->allows($user));
    }

    public function test_canceled_subscription_with_a_lapsed_period_end_is_blocked(): void
    {
        $user = $this->user();
        $profile = new BillingProfile($user, trialEndsAt: new \DateTimeImmutable('-30 days'));
        $profile->status = BillingStatus::Canceled;
        $profile->currentPeriodEnd = new \DateTimeImmutable('-1 day');

        self::assertFalse($this->gate(true, $profile)->allows($user));
    }
}
