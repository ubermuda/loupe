<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Entity\BillingStatus;
use App\Module\Billing\Repository\BillingProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final readonly class SeedBillingStateHandler
{
    public function __construct(
        private FeatureFlagRepository $featureFlags,
        private BillingProfileRepository $billingProfiles,
        private RunTrialSweepHandler $runTrialSweep,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(SeedBillingStateCommand $command): SeedBillingStateView
    {
        $flag = $this->featureFlags->findOneBy(['name' => 'billing.enabled']);
        if (null === $flag) {
            $flag = new FeatureFlag('billing.enabled', FeatureFlagType::Bool, $command->billingEnabled);
            $this->em->persist($flag);
        } else {
            $flag->type = FeatureFlagType::Bool;
            $flag->value = $command->billingEnabled;
        }

        $seeded = '' !== $command->state;
        if ($seeded) {
            $this->seedState(
                $command->user ?? throw new BadRequestHttpException('Seeding a billing state requires an authenticated user.'),
                $command->state,
            );
        }

        $this->em->flush();

        // After the flush, so freshly seeded rows are visible to the sweep's
        // candidate queries.
        $sweep = $command->sweep ? ($this->runTrialSweep)(new RunTrialSweepCommand()) : null;

        return new SeedBillingStateView(
            billingEnabled: $command->billingEnabled,
            state: $seeded ? $command->state : null,
            sweep: $sweep,
        );
    }

    private function seedState(User $user, string $state): void
    {
        $profile = $this->billingProfiles->findOneByUser($user);
        if (null === $profile) {
            $profile = new BillingProfile($user, trialEndsAt: new \DateTimeImmutable('+14 days'));
            $this->em->persist($profile);
        }

        // Baseline: wipe everything a previous state (or a previous run's
        // sweep) may have left behind, then let the named state set what it
        // needs. This is what makes states order-independent.
        $profile->stripeCustomerId = null;
        $profile->stripeSubscriptionId = null;
        $profile->currentPeriodEnd = null;
        $profile->lastStripeEventAt = null;
        $profile->lastStripeEventId = null;
        $profile->lastStripeEventType = null;
        $profile->surveySentAt = null;
        $profile->cancelSurveySentAt = null;
        $user->disabledAt = null;

        $now = new \DateTimeImmutable();

        switch ($state) {
            case 'fresh-trial':
                $profile->status = BillingStatus::Trialing;
                $profile->trialEndsAt = $now->modify('+14 days');
                break;
            case 'expired-trial':
                $profile->status = BillingStatus::Trialing;
                $profile->trialEndsAt = $now->modify('-1 day');
                break;
            case 'canceled-past-period':
                $profile->status = BillingStatus::Canceled;
                $profile->trialEndsAt = $now->modify('-30 days');
                $profile->currentPeriodEnd = $now->modify('-1 day');
                // Matches what a real cancellation webhook leaves behind —
                // BillingProfile::isCurrent() requires this event type before
                // it will honor currentPeriodEnd for a Canceled profile.
                $profile->lastStripeEventType = BillingProfile::SUBSCRIPTION_DELETED_EVENT_TYPE;
                break;
            case 'disabled':
                $profile->status = BillingStatus::Trialing;
                $profile->trialEndsAt = $now->modify('-1 day');
                $profile->surveySentAt = $now;
                $user->disabledAt = $now;
                break;
            default:
                throw new BadRequestHttpException(sprintf('Unknown billing state "%s".', $state));
        }
    }
}
