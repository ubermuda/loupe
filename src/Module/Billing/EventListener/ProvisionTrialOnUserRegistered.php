<?php

declare(strict_types=1);

namespace App\Module\Billing\EventListener;

use App\Module\Account\Event\UserRegistered;
use App\Module\Billing\Service\TrialProvisioner;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * Anchors the trial clock to registration: while `billing.enabled` is on, a new
 * account gets its BillingProfile (and so its trialEndsAt) immediately, instead
 * of on first paywalled request. PaywallGate keeps its own ensureProfile() call
 * as the safety net for accounts that predate this listener or raced past it.
 *
 * The flag check is what keeps the two consistent. Without it, a deployment
 * running with billing off accumulates profiles whose trial clock ticks down
 * unwatched, so flipping the flag on would instantly paywall every account
 * registered more than a trial ago — the opposite of PaywallGate's promise that
 * the flip starts a full trial for everyone.
 */
#[AsEventListener]
final readonly class ProvisionTrialOnUserRegistered
{
    public function __construct(
        private TrialProvisioner $trialProvisioner,
        private FeatureFlagService $featureFlags,
    ) {
    }

    public function __invoke(UserRegistered $event): void
    {
        if (!$this->featureFlags->isEnabled('billing.enabled')) {
            return;
        }

        $this->trialProvisioner->ensureProfile($event->user);
    }
}
