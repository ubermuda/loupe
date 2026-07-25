<?php

declare(strict_types=1);

namespace App\Module\Billing\Service;

use App\Module\Account\Entity\User;

/**
 * The app's entire Stripe surface. Handlers depend on this interface only, so
 * they are unit-testable with a stub and no class outside StripeGateway needs
 * to know the vendor SDK exists.
 */
interface StripeGatewayInterface
{
    /** @return string the Stripe customer id */
    public function createCustomer(User $user): string;

    /** @return string the hosted Checkout URL to redirect to */
    public function createCheckoutSession(string $customerId, string $priceId, string $successUrl, string $cancelUrl): string;

    /** @return string the hosted portal URL to redirect to */
    public function createPortalSession(string $customerId, string $returnUrl): string;

    public function retrievePrice(string $priceId): PriceView;

    public function cancelSubscription(string $subscriptionId): void;
}
