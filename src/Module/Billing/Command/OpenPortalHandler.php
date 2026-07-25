<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

use App\Exception\DomainErrors;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Billing\Service\StripeGatewayInterface;
use Psr\Log\LoggerInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

final readonly class OpenPortalHandler
{
    public function __construct(
        private BillingProfileRepository $billingProfiles,
        private StripeGatewayInterface $stripe,
        private FeatureFlagService $featureFlags,
        private LoggerInterface $logger,
    ) {
    }

    /** @return string the hosted customer-portal URL */
    public function __invoke(OpenPortalCommand $command): string
    {
        if (!$this->featureFlags->isEnabled('billing.enabled')) {
            throw new DomainErrors(['billing' => 'billing.error.disabled']);
        }

        $customerId = $this->billingProfiles->findOneByUser($command->user)?->stripeCustomerId;
        if (null === $customerId) {
            // Nobody who never reached Checkout has a portal to open.
            throw new DomainErrors(['portal' => 'billing.error.no_customer']);
        }

        try {
            $url = $this->stripe->createPortalSession($customerId, $command->returnUrl);
        } catch (\Throwable $e) {
            // Stripe being down is a bad minute, not a bug: surface it as a
            // domain failure so the user gets "try again later", not a 500.
            $this->logger->error('billing.portal.stripe_failed', [
                'userId' => (string) $command->user->id,
                'error' => $e->getMessage(),
            ]);

            throw new DomainErrors(['portal' => 'billing.error.stripe_unavailable']);
        }

        $this->logger->info('billing.portal.opened', ['userId' => (string) $command->user->id]);

        return $url;
    }
}
