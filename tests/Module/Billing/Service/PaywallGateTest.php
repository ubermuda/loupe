<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Service;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Entity\BillingStatus;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Billing\Service\PaywallGate;
use App\Module\Billing\Service\TrialProvisioner;
use App\Tests\Support\BillingGrants;
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
        return new User(fullName: 'Gated User', email: 'gated@example.com', password: 'irrelevant');
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

        self::assertTrue($this->gate(true, BillingGrants::profileWithTrial($user, new \DateTimeImmutable('+5 days')))->allows($user));
    }

    public function test_expired_trial_without_any_other_grant_is_blocked(): void
    {
        $user = $this->user();

        self::assertFalse($this->gate(true, BillingGrants::profileWithTrial($user, new \DateTimeImmutable('-1 day')))->allows($user));
    }

    public function test_a_running_stripe_subscription_after_the_trial_is_allowed(): void
    {
        $user = $this->user();
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('-30 days'));
        BillingGrants::stripe($profile, BillingStatus::Active, new \DateTimeImmutable('+20 days'));

        self::assertTrue($this->gate(true, $profile)->allows($user));
    }

    public function test_a_stripe_subscription_past_its_period_is_blocked(): void
    {
        $user = $this->user();
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('-30 days'));
        BillingGrants::stripe($profile, BillingStatus::PastDue, new \DateTimeImmutable('-1 day'));

        self::assertFalse($this->gate(true, $profile)->allows($user));
    }

    public function test_a_comp_with_no_end_date_is_allowed_on_its_own(): void
    {
        $user = $this->user();
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('-30 days'));
        BillingGrants::comp($profile);

        self::assertTrue($this->gate(true, $profile)->allows($user));
    }

    public function test_a_comp_survives_a_canceled_stripe_subscription(): void
    {
        $user = $this->user();
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('-30 days'));
        BillingGrants::stripe($profile, BillingStatus::Canceled, new \DateTimeImmutable('-1 day'));
        BillingGrants::comp($profile);

        self::assertTrue($this->gate(true, $profile)->allows($user));
    }

    /**
     * A mid-period cancel: the customer already paid through the period end, so
     * the grant runs to that date and stops on its own.
     */
    public function test_a_mid_period_cancel_keeps_access_until_the_period_end(): void
    {
        $user = $this->user();
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('-30 days'));
        $canceled = BillingGrants::stripe($profile, BillingStatus::Canceled, new \DateTimeImmutable('+5 days'));

        self::assertTrue($this->gate(true, $profile)->allows($user));
        self::assertFalse($canceled->isCurrent(new \DateTimeImmutable('+6 days')));
    }
}
