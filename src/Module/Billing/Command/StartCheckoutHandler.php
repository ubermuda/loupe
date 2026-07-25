<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

use App\Exception\DomainErrors;
use App\Module\Billing\Service\ActivePriceProvider;
use App\Module\Billing\Service\StripeGatewayInterface;
use App\Module\Billing\Service\TrialProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

final readonly class StartCheckoutHandler
{
    public function __construct(
        private TrialProvisioner $trialProvisioner,
        private ActivePriceProvider $prices,
        private StripeGatewayInterface $stripe,
        private FeatureFlagService $featureFlags,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /** @return string the hosted Checkout URL */
    public function __invoke(StartCheckoutCommand $command): string
    {
        // The master switch gates the actions too, not just the paywall — a
        // direct POST while billing is dark must not create Stripe state.
        if (!$this->featureFlags->isEnabled('billing.enabled')) {
            throw new DomainErrors(['billing' => 'billing.error.disabled']);
        }

        $priceId = $this->prices->activePriceId();
        if (null === $priceId) {
            throw new DomainErrors(['price' => 'billing.error.no_active_price']);
        }

        $profile = $this->trialProvisioner->ensureProfile($command->user);

        // Never open a second Checkout for a customer Stripe already holds a
        // subscription for — that bills them twice. Whatever is wrong with the
        // existing one (unpaid, card expired) is fixed in the portal.
        if ($profile->hasLiveSubscription()) {
            throw new DomainErrors(['billing' => 'billing.error.subscription_exists']);
        }

        try {
            $customerId = $profile->stripeCustomerId;
            if (null === $customerId) {
                $customerId = $this->stripe->createCustomer($command->user);
                $profile->stripeCustomerId = $customerId;
                $this->em->flush();
            }

            $url = $this->stripe->createCheckoutSession($customerId, $priceId, $command->successUrl, $command->cancelUrl);
        } catch (\Throwable $e) {
            // Stripe being down or rejecting the call is a bad minute, not a
            // bug: surface it as a domain failure so the user gets the "try
            // again later" page instead of a 500.
            $this->logger->error('billing.checkout.stripe_failed', [
                'userId' => (string) $command->user->id,
                'error' => $e->getMessage(),
            ]);

            throw new DomainErrors(['billing' => 'billing.error.stripe_unavailable']);
        }

        $this->logger->info('billing.checkout.started', ['userId' => (string) $command->user->id, 'priceId' => $priceId]);

        return $url;
    }
}
