<?php

declare(strict_types=1);

namespace App\Module\Billing\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * Resolves the price the app currently sells, from the `billing.stripe_price_id`
 * flag. The displayed amount is read from Stripe rather than configured twice,
 * so the subscribe page can never quote a number Checkout will not charge.
 */
final readonly class ActivePriceProvider
{
    private const int CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private FeatureFlagService $featureFlags,
        private StripeGatewayInterface $stripe,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    public function get(): ?PriceView
    {
        $priceId = $this->activePriceId();
        if (null === $priceId) {
            return null;
        }

        try {
            return $this->cache->get(
                'billing_price_'.md5($priceId),
                function (ItemInterface $item) use ($priceId): PriceView {
                    $item->expiresAfter(self::CACHE_TTL_SECONDS);

                    return $this->stripe->retrievePrice($priceId);
                },
            );
        } catch (\Throwable $e) {
            // A Stripe outage or a misconfigured price must not 500 the page:
            // it renders without an amount, and the flag stays inspectable.
            $this->logger->warning('billing.price.fetch_failed', ['priceId' => $priceId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public function activePriceId(): ?string
    {
        $priceId = $this->featureFlags->getValue('billing.stripe_price_id');

        return is_string($priceId) && '' !== $priceId ? $priceId : null;
    }
}
