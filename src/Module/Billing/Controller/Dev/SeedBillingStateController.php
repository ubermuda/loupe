<?php

declare(strict_types=1);

namespace App\Module\Billing\Controller\Dev;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Billing\Command\RunTrialSweepCommand;
use App\Module\Billing\Command\RunTrialSweepHandler;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Entity\BillingStatus;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Routing\PaywallExempt;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

/**
 * Dev-only endpoint that puts billing into a chosen state: flips the
 * `billing.enabled` flag, seeds the authenticated user's billing profile into
 * a named lifecycle state, and, on request, runs the trial-end sweep. Used
 * exclusively by Playwright e2e tests — not available in production
 * (When('dev')). The route is allowlisted in RequireSubscriptionListener so a
 * paywalled session can still call it to switch billing back off.
 *
 * `state` values (each fully re-seeds the profile, so states can be applied
 * in any order):
 *   - fresh-trial:          an untouched trial with 14 days left — the reset/cleanup state
 *   - expired-trial:        a trial that ended yesterday, not yet swept
 *   - canceled-past-period: a canceled subscription whose paid period ended yesterday, not yet swept
 *   - disabled:             the post-sweep result of an expired trial (account disabled, survey marked)
 *
 * `sweep=1` runs the trial-end sweep synchronously and returns its counts. The
 * feature-flag reader is request-cached, so flip `enabled` and run the sweep
 * in separate requests — a same-request flip may be invisible to the sweeper.
 */
#[PaywallExempt]
#[Route(
    '/dev/billing-state',
    name: 'app_dev_billing_state',
    methods: ['GET'],
)]
#[When('dev')]
final class SeedBillingStateController extends AppController
{
    public function __construct(
        private readonly FeatureFlagRepository $featureFlags,
        private readonly BillingProfileRepository $billingProfiles,
        private readonly RunTrialSweepHandler $runTrialSweep,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $enabled = $request->query->getBoolean('enabled');

        $flag = $this->featureFlags->findOneBy(['name' => 'billing.enabled']);
        if (null === $flag) {
            $flag = new FeatureFlag('billing.enabled', FeatureFlagType::Bool, $enabled);
            $this->em->persist($flag);
        } else {
            $flag->type = FeatureFlagType::Bool;
            $flag->value = $enabled;
        }

        $state = $request->query->getString('state');
        if ('' !== $state) {
            $user = $this->getUser();
            if (!$user instanceof User) {
                throw new BadRequestHttpException('Seeding a billing state requires an authenticated user.');
            }
            $this->seedState($user, $state);
        }

        $this->em->flush();

        $payload = ['billingEnabled' => $enabled];
        if ('' !== $state) {
            $payload['state'] = $state;
        }

        // After the flush, so freshly seeded rows are visible to the sweep's
        // candidate queries.
        if ($request->query->getBoolean('sweep')) {
            $result = ($this->runTrialSweep)(new RunTrialSweepCommand());
            $payload['sweep'] = [
                'disabled' => $result->disabled,
                'churnedSurveys' => $result->churnedSurveys,
                'subscriberSurveys' => $result->subscriberSurveys,
                'cancelSurveys' => $result->cancelSurveys,
                'failed' => $result->failed,
            ];
        }

        return $this->json($payload);
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
