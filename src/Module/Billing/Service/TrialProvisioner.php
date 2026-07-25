<?php

declare(strict_types=1);

namespace App\Module\Billing\Service;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Repository\BillingProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * The single place a trial is created. Every entry point that needs a billing
 * profile — the paywall gate, the subscribe page, checkout — goes through here,
 * so a user gets exactly one trial no matter which door they came in by.
 */
final readonly class TrialProvisioner
{
    public const int DEFAULT_TRIAL_DAYS = 14;

    public function __construct(
        private BillingProfileRepository $billingProfiles,
        private FeatureFlagService $featureFlags,
        private EntityManagerInterface $em,
    ) {
    }

    public function ensureProfile(User $user): BillingProfile
    {
        $profile = $this->billingProfiles->findOneByUser($user);
        if (null !== $profile) {
            return $profile;
        }

        // Read-check-write on a row that does not exist yet, so there is no row
        // to lock. A transaction-scoped advisory lock keyed on the user serves
        // instead: concurrent first requests queue here, and the loser sees the
        // winner's row on its re-read rather than colliding on the unique index.
        // Recovering from that collision is not an option — a failed flush()
        // closes the entity manager, leaving nothing able to re-read the row.
        return $this->em->wrapInTransaction(function () use ($user): BillingProfile {
            $this->em->getConnection()->executeStatement(
                'SELECT pg_advisory_xact_lock(hashtext(?))',
                ['billing_profile_'.(string) $user->id],
            );

            $profile = $this->billingProfiles->findOneByUser($user);
            if (null !== $profile) {
                return $profile;
            }

            $days = max(1, $this->featureFlags->getIntValue('billing.trial_days', self::DEFAULT_TRIAL_DAYS));
            $profile = new BillingProfile($user, trialEndsAt: new \DateTimeImmutable(sprintf('+%d days', $days)));

            $this->em->persist($profile);
            $this->em->flush();

            return $profile;
        });
    }
}
