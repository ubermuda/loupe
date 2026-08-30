<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Entity\BillingStatus;
use App\Module\Billing\Entity\Subscription;
use App\Module\Billing\Entity\SubscriptionKind;
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
            $profile = new BillingProfile($user);
            $this->em->persist($profile);
        }

        // Baseline: wipe every grant and every Stripe marker a previous state
        // (or a previous run's sweep) may have left behind, then let the named
        // state build what it needs. This makes states order-independent.
        foreach ($profile->subscriptions as $subscription) {
            $this->em->remove($subscription);
        }
        $profile->subscriptions->clear();

        $profile->stripeCustomerId = null;
        $profile->lastStripeEventAt = null;
        $profile->lastStripeEventId = null;
        $profile->lastStripeEventType = null;
        $user->disabledAt = null;

        $now = new \DateTimeImmutable();

        switch ($state) {
            case 'fresh-trial':
                $this->em->persist(new Subscription($profile, SubscriptionKind::Trial, $now, $now->modify('+14 days')));
                break;
            case 'expired-trial':
                $this->em->persist(new Subscription($profile, SubscriptionKind::Trial, $now->modify('-15 days'), $now->modify('-1 day')));
                break;
            case 'canceled-past-period':
                $this->em->persist(new Subscription($profile, SubscriptionKind::Trial, $now->modify('-44 days'), $now->modify('-30 days')));
                $this->em->persist($this->canceledStripeGrant($profile, $now));
                $profile->stripeCustomerId = 'cus_seeded';
                $profile->lastStripeEventType = BillingProfile::SUBSCRIPTION_DELETED_EVENT_TYPE;
                break;
            case 'disabled':
                $trial = new Subscription($profile, SubscriptionKind::Trial, $now->modify('-15 days'), $now->modify('-1 day'));
                $trial->surveySentAt = $now;
                $this->em->persist($trial);
                $user->disabledAt = $now;
                break;
            default:
                throw new BadRequestHttpException(sprintf('Unknown billing state "%s".', $state));
        }
    }

    private function canceledStripeGrant(BillingProfile $profile, \DateTimeImmutable $now): Subscription
    {
        $grant = new Subscription($profile, SubscriptionKind::Stripe, $now->modify('-30 days'), $now->modify('-1 day'));
        $grant->stripeSubscriptionId = sprintf('sub_seeded_%s', $profile->user->id);
        $grant->stripeStatus = BillingStatus::Canceled;

        return $grant;
    }
}
