<?php

declare(strict_types=1);

namespace App\Module\Billing\Service;

use App\Module\Account\Entity\User;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

final readonly class PaywallGate
{
    public function __construct(
        private FeatureFlagService $featureFlags,
        private TrialProvisioner $trialProvisioner,
    ) {
    }

    /**
     * Whether the user may use the app right now. Provisions the trial profile
     * on first evaluation after the billing flag is enabled, so pre-existing
     * accounts get a full trial from the flag flip and new accounts from first
     * use. While the flag is off nothing is created at all.
     */
    public function allows(User $user): bool
    {
        if (!$this->featureFlags->isEnabled('billing.enabled')) {
            return true;
        }

        return $this->trialProvisioner->ensureProfile($user)->isCurrent(new \DateTimeImmutable());
    }
}
