<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Billing\Service\ActivePriceProvider;
use App\Module\Billing\Service\TrialProvisioner;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

final readonly class ShowSubscribeHandler
{
    private const int SECONDS_PER_DAY = 86400;

    public function __construct(
        private BillingProfileRepository $billingProfiles,
        private ActivePriceProvider $prices,
        private TrialProvisioner $trialProvisioner,
        private FeatureFlagService $featureFlags,
    ) {
    }

    public function __invoke(ShowSubscribeCommand $command): ShowSubscribeView
    {
        // Provision rather than merely read: the subscribe page is allowlisted,
        // so a user arriving via the nav link — before any gated request ran —
        // must still get their trial created and see its status.
        $billingEnabled = $this->featureFlags->isEnabled('billing.enabled');
        $profile = $billingEnabled
            ? $this->trialProvisioner->ensureProfile($command->user)
            : $this->billingProfiles->findOneByUser($command->user);

        $now = new \DateTimeImmutable();
        $subscribed = null !== $profile && null !== $profile->stripeSubscriptionId && $profile->isCurrent($now);

        return new ShowSubscribeView(
            profile: $profile,
            price: $this->prices->get(),
            billingEnabled: $billingEnabled,
            subscribed: $subscribed,
            trialing: !$subscribed && null !== $profile && $profile->isCurrent($now),
            trialDaysLeft: null === $profile ? 0 : $this->daysLeft($profile, $now),
        );
    }

    private function daysLeft(BillingProfile $profile, \DateTimeImmutable $now): int
    {
        if ($now >= $profile->trialEndsAt) {
            return 0;
        }

        return (int) ceil(($profile->trialEndsAt->getTimestamp() - $now->getTimestamp()) / self::SECONDS_PER_DAY);
    }
}
