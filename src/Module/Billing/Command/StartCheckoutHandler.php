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

        $customerId = $profile->stripeCustomerId;
        if (null === $customerId) {
            $customerId = $this->stripe->createCustomer($command->user);
            $profile->stripeCustomerId = $customerId;
            $this->em->flush();
        }

        $url = $this->stripe->createCheckoutSession($customerId, $priceId, $command->successUrl, $command->cancelUrl);

        $this->logger->info('billing.checkout.started', ['userId' => (string) $command->user->id, 'priceId' => $priceId]);

        return $url;
    }
}
