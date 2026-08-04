<?php

declare(strict_types=1);

namespace App\Module\Billing\Service;

use App\Module\Account\Entity\User;
use Stripe\StripeClient;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsAlias(StripeGatewayInterface::class)]
final readonly class StripeGateway implements StripeGatewayInterface
{
    public function __construct(
        #[Autowire(env: 'STRIPE_SECRET_KEY')]
        private string $secretKey,
    ) {
    }

    /**
     * The client is built per call rather than injected. StripeClient rejects an
     * empty API key in its constructor, so injecting it would make a deployment
     * that has not configured Stripe yet fail while the service graph is built —
     * that is, before any of the caller's error handling exists, taking down the
     * subscribe page and the account page with a 500. Constructing it here puts
     * the failure inside the calls the callers already degrade gracefully from.
     */
    private function stripe(): StripeClient
    {
        return new StripeClient($this->secretKey);
    }

    #[\Override]
    public function createCustomer(User $user): string
    {
        // Nothing calls customers->update, so the name written here is the
        // customer's name on every future invoice.
        $customer = $this->stripe()->customers->create([
            'email' => $user->email,
            'name' => $user->fullName,
            'metadata' => ['app_user_id' => (string) $user->id],
        ]);

        return $customer->id;
    }

    #[\Override]
    public function createCheckoutSession(string $customerId, string $priceId, string $successUrl, string $cancelUrl, string $idempotencyKey): string
    {
        $session = $this->stripe()->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $customerId,
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ], [
            // A double-click, a retried redirect or a second tab must not open a
            // second Checkout: two completed sessions mean two subscriptions and
            // a double charge. Stripe replays the first session for a repeated
            // key; the caller decides what "the same attempt" means.
            'idempotency_key' => $idempotencyKey,
        ]);

        return $session->url ?? throw new \RuntimeException('Stripe checkout session has no URL');
    }

    #[\Override]
    public function createPortalSession(string $customerId, string $returnUrl): string
    {
        $session = $this->stripe()->billingPortal->sessions->create([
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ]);

        return $session->url;
    }

    #[\Override]
    public function retrievePrice(string $priceId): PriceView
    {
        $price = $this->stripe()->prices->retrieve($priceId);

        // A subscription-mode Checkout needs an active, recurring, fixed-amount
        // price — anything else (one-time, metered/tiered, archived) would render
        // a wrong number on the subscribe page and then fail inside Checkout.
        // Reject it here, where the caller can degrade gracefully.
        if (!$price->active || null === $price->recurring || !is_int($price->unit_amount)) {
            throw new \RuntimeException(sprintf('Price %s is not an active fixed-amount recurring price', $priceId));
        }

        return new PriceView(
            priceId: $price->id,
            unitAmount: $price->unit_amount,
            currency: $price->currency,
            interval: $price->recurring->interval,
        );
    }

    #[\Override]
    public function cancelSubscription(string $subscriptionId): void
    {
        $this->stripe()->subscriptions->cancel($subscriptionId);
    }
}
