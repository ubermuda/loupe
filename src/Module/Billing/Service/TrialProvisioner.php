<?php

declare(strict_types=1);

namespace App\Module\Billing\Service;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Repository\BillingProfileRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
        private BillingProfileRepository $profiles,
        private FeatureFlagService $featureFlags,
        private EntityManagerInterface $em,
    ) {
    }

    public function ensureProfile(User $user): BillingProfile
    {
        $profile = $this->profiles->findOneByUser($user);
        if (null !== $profile) {
            return $profile;
        }

        $days = max(1, $this->featureFlags->getIntValue('billing.trial_days', self::DEFAULT_TRIAL_DAYS));
        $profile = new BillingProfile($user, trialEndsAt: new \DateTimeImmutable(sprintf('+%d days', $days)));

        try {
            $this->em->persist($profile);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // A concurrent first request won the race — theirs is the profile.
            // The unique FK on user_id is what makes this safe.
            return $this->profiles->findOneByUser($user) ?? throw new \RuntimeException('billing profile vanished after unique violation');
        }

        return $profile;
    }
}
