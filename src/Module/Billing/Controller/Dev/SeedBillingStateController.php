<?php

declare(strict_types=1);

namespace App\Module\Billing\Controller\Dev;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Repository\BillingProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

/**
 * Dev-only endpoint that puts billing into a chosen state: flips the
 * `billing.enabled` flag and, on request, expires the authenticated user's
 * trial. Used exclusively by Playwright e2e tests — not available in production
 * (When('dev')). The route is allowlisted in RequireSubscriptionListener so a
 * paywalled session can still call it to switch billing back off.
 */
#[Route('/dev/billing-state', name: 'app_dev_billing_state', methods: ['GET'])]
#[When('dev')]
final class SeedBillingStateController extends AppController
{
    public function __construct(
        private readonly FeatureFlagRepository $flags,
        private readonly BillingProfileRepository $profiles,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $enabled = $request->query->getBoolean('enabled');

        $flag = $this->flags->findOneBy(['name' => 'billing.enabled']);
        if (null === $flag) {
            $flag = new FeatureFlag('billing.enabled', FeatureFlagType::Bool, $enabled);
            $this->em->persist($flag);
        } else {
            $flag->type = FeatureFlagType::Bool;
            $flag->value = $enabled;
        }

        $trialExpired = false;
        $user = $this->getUser();
        if ($user instanceof User && $request->query->getBoolean('expireTrial')) {
            $profile = $this->profiles->findOneByUser($user);
            if (null === $profile) {
                $profile = new BillingProfile($user, trialEndsAt: new \DateTimeImmutable('-1 day'));
                $this->em->persist($profile);
            } else {
                $profile->trialEndsAt = new \DateTimeImmutable('-1 day');
            }
            $trialExpired = true;
        }

        $this->em->flush();

        return $this->json(['billingEnabled' => $enabled, 'trialExpired' => $trialExpired]);
    }
}
