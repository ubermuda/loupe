<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Service;

use App\Module\Billing\Service\ActivePriceProvider;
use App\Module\Billing\Service\PriceView;
use App\Module\Billing\Service\StripeGatewayInterface;
use App\Tests\Support\FeatureFlags;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ActivePriceProviderTest extends TestCase
{
    private function price(): PriceView
    {
        return new PriceView(priceId: 'price_123', unitAmount: 900, currency: 'eur', interval: 'month');
    }

    public function test_returns_the_price_of_the_flag_configured_id(): void
    {
        $stripe = $this->createStub(StripeGatewayInterface::class);
        $stripe->method('retrievePrice')->willReturn($this->price());

        $provider = new ActivePriceProvider(
            FeatureFlags::service(['billing.stripe_price_id' => 'price_123']),
            $stripe,
            new ArrayAdapter(),
            new NullLogger(),
        );

        $price = $provider->get();

        self::assertNotNull($price);
        self::assertSame('price_123', $price->priceId);
        self::assertSame(900, $price->unitAmount);
        self::assertSame('price_123', $provider->activePriceId());
    }

    public function test_the_price_is_fetched_once_and_cached(): void
    {
        $stripe = $this->createMock(StripeGatewayInterface::class);
        $stripe->expects($this->once())->method('retrievePrice')->willReturn($this->price());

        $provider = new ActivePriceProvider(
            FeatureFlags::service(['billing.stripe_price_id' => 'price_123']),
            $stripe,
            new ArrayAdapter(),
            new NullLogger(),
        );

        $provider->get();
        $provider->get();
    }

    public function test_unset_flag_yields_no_price_and_no_stripe_call(): void
    {
        $stripe = $this->createMock(StripeGatewayInterface::class);
        $stripe->expects($this->never())->method('retrievePrice');

        $provider = new ActivePriceProvider(FeatureFlags::service(), $stripe, new ArrayAdapter(), new NullLogger());

        self::assertNull($provider->get());
        self::assertNull($provider->activePriceId());
    }

    public function test_empty_flag_yields_no_price(): void
    {
        $stripe = $this->createMock(StripeGatewayInterface::class);
        $stripe->expects($this->never())->method('retrievePrice');

        $provider = new ActivePriceProvider(
            FeatureFlags::service(['billing.stripe_price_id' => '']),
            $stripe,
            new ArrayAdapter(),
            new NullLogger(),
        );

        self::assertNull($provider->get());
    }

    public function test_a_stripe_failure_degrades_to_no_price(): void
    {
        $stripe = $this->createStub(StripeGatewayInterface::class);
        $stripe->method('retrievePrice')->willThrowException(new \RuntimeException('stripe is down'));

        $provider = new ActivePriceProvider(
            FeatureFlags::service(['billing.stripe_price_id' => 'price_123']),
            $stripe,
            new ArrayAdapter(),
            new NullLogger(),
        );

        self::assertNull($provider->get());
        self::assertSame('price_123', $provider->activePriceId());
    }
}
