<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Account\Event\UserRegistered;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\EventListener\ProvisionTrialOnUserRegistered;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Billing\Service\TrialProvisioner;
use App\Tests\Support\FeatureFlags;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * TrialProvisioner is final and cannot be doubled, so the listener is
 * exercised against a real one whose repository already holds the user's
 * profile — the findOneByUser() expectation is what proves whether
 * ensureProfile() ran for the event's user, with no persistence happening.
 */
final class ProvisionTrialOnUserRegisteredTest extends TestCase
{
    public function test_it_ensures_a_billing_profile_for_the_registered_user(): void
    {
        $user = $this->user();
        $profile = new BillingProfile($user, trialEndsAt: new \DateTimeImmutable('+14 days'));

        $profiles = $this->createMock(BillingProfileRepository::class);
        $profiles->expects($this->once())->method('findOneByUser')->with($user)->willReturn($profile);

        $this->listener($profiles, ['billing.enabled' => true])(new UserRegistered($user));
    }

    /**
     * While the flag is off nothing is created at all, matching
     * PaywallGate::allows() — the trial clock must only start once billing is
     * actually on, or flipping the flag would paywall every older account at once.
     */
    public function test_flag_off_provisions_nothing(): void
    {
        $profiles = $this->createMock(BillingProfileRepository::class);
        $profiles->expects($this->never())->method('findOneByUser');

        $this->listener($profiles, ['billing.enabled' => false])(new UserRegistered($this->user()));
    }

    public function test_missing_flag_is_treated_as_off(): void
    {
        $profiles = $this->createMock(BillingProfileRepository::class);
        $profiles->expects($this->never())->method('findOneByUser');

        $this->listener($profiles, [])(new UserRegistered($this->user()));
    }

    private function user(): User
    {
        return new User(fullName: 'Fresh User', email: 'fresh@example.com');
    }

    /** @param array<string, bool|int|string> $flags */
    private function listener(BillingProfileRepository $profiles, array $flags): ProvisionTrialOnUserRegistered
    {
        return new ProvisionTrialOnUserRegistered(
            new TrialProvisioner($profiles, FeatureFlags::service(), $this->createStub(EntityManagerInterface::class)),
            FeatureFlags::service($flags),
        );
    }
}
