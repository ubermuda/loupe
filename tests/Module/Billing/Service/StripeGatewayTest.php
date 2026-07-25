<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Service;

use App\Module\Account\Entity\User;
use App\Module\Billing\Service\StripeGateway;
use App\Tests\Support\RecordingStripeHttpClient;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

final class StripeGatewayTest extends TestCase
{
    private RecordingStripeHttpClient $http;

    /** @param array<string, mixed> $responseBody */
    private function gateway(array $responseBody): StripeGateway
    {
        $this->http = new RecordingStripeHttpClient($responseBody);
        ApiRequestor::setHttpClient($this->http);

        return new StripeGateway(new StripeClient('sk_test_dummy'));
    }

    #[\Override]
    protected function tearDown(): void
    {
        // The hook is process-global — leaving a recorder installed would leak
        // into every later test that touches Stripe. Restoring the SDK's own
        // default client is the reset.
        ApiRequestor::setHttpClient(CurlClient::instance());
    }

    public function test_checkout_session_is_subscription_mode_with_price_and_urls(): void
    {
        $gateway = $this->gateway(['id' => 'cs_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.test/s']);

        $url = $gateway->createCheckoutSession('cus_1', 'price_1', 'https://app/success', 'https://app/cancel');

        self::assertSame('https://checkout.stripe.test/s', $url);
        $request = $this->http->requests[0];
        self::assertSame('post', $request['method']);
        self::assertStringEndsWith('/v1/checkout/sessions', $request['url']);
        self::assertSame('subscription', $request['params']['mode']);
        self::assertSame('cus_1', $request['params']['customer']);
        self::assertSame([['price' => 'price_1', 'quantity' => 1]], $request['params']['line_items']);
        self::assertSame('https://app/success', $request['params']['success_url']);
        self::assertSame('https://app/cancel', $request['params']['cancel_url']);
    }

    public function test_checkout_sessions_are_created_idempotently_per_customer_price_and_day(): void
    {
        $gateway = $this->gateway(['id' => 'cs_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.test/s']);

        $gateway->createCheckoutSession('cus_1', 'price_1', 'https://app/success', 'https://app/cancel');
        $gateway->createCheckoutSession('cus_1', 'price_1', 'https://app/success', 'https://app/cancel');

        $keys = array_map(
            static fn (array $request): array => array_values(array_filter(
                $request['headers'],
                static fn (string $header): bool => str_starts_with($header, 'Idempotency-Key: '),
            )),
            $this->http->requests,
        );

        self::assertCount(1, $keys[0], 'the checkout call must carry an idempotency key');
        self::assertSame($keys[0], $keys[1], 'a repeated checkout must reuse the same key');
        self::assertStringContainsString('checkout_cus_1_price_1_', $keys[0][0]);
    }

    public function test_checkout_session_without_url_is_rejected(): void
    {
        $gateway = $this->gateway(['id' => 'cs_1', 'object' => 'checkout.session']);

        $this->expectException(\RuntimeException::class);
        $gateway->createCheckoutSession('cus_1', 'price_1', 'https://app/success', 'https://app/cancel');
    }

    public function test_portal_session_passes_customer_and_return_url(): void
    {
        $gateway = $this->gateway(['id' => 'bps_1', 'object' => 'billing_portal.session', 'url' => 'https://portal.stripe.test/s']);

        $url = $gateway->createPortalSession('cus_1', 'https://app/account');

        self::assertSame('https://portal.stripe.test/s', $url);
        $request = $this->http->requests[0];
        self::assertStringEndsWith('/v1/billing_portal/sessions', $request['url']);
        self::assertSame('cus_1', $request['params']['customer']);
        self::assertSame('https://app/account', $request['params']['return_url']);
    }

    public function test_customer_creation_sends_email_name_and_app_user_id(): void
    {
        $gateway = $this->gateway(['id' => 'cus_42', 'object' => 'customer']);

        $customerId = $gateway->createCustomer(new User(
            username: 'alice',
            fullName: 'Alice A',
            email: 'alice@example.com',
            password: 'irrelevant',
        ));

        self::assertSame('cus_42', $customerId);
        $request = $this->http->requests[0];
        self::assertStringEndsWith('/v1/customers', $request['url']);
        self::assertSame('alice@example.com', $request['params']['email']);
        self::assertSame('Alice A', $request['params']['name']);
        self::assertArrayHasKey('app_user_id', $request['params']['metadata']);
    }

    public function test_price_retrieve_maps_a_recurring_price(): void
    {
        $gateway = $this->gateway([
            'id' => 'price_1',
            'object' => 'price',
            'active' => true,
            'unit_amount' => 900,
            'currency' => 'eur',
            'recurring' => ['interval' => 'month'],
        ]);

        $price = $gateway->retrievePrice('price_1');

        self::assertSame('price_1', $price->priceId);
        self::assertSame(900, $price->unitAmount);
        self::assertSame('eur', $price->currency);
        self::assertSame('month', $price->interval);
        self::assertSame('get', $this->http->requests[0]['method']);
    }

    public function test_price_retrieve_rejects_non_recurring_prices(): void
    {
        $gateway = $this->gateway(['id' => 'price_1', 'object' => 'price', 'active' => true, 'unit_amount' => 900, 'currency' => 'eur', 'recurring' => null]);

        $this->expectException(\RuntimeException::class);
        $gateway->retrievePrice('price_1');
    }

    public function test_price_retrieve_rejects_archived_prices(): void
    {
        $gateway = $this->gateway([
            'id' => 'price_1',
            'object' => 'price',
            'active' => false,
            'unit_amount' => 900,
            'currency' => 'eur',
            'recurring' => ['interval' => 'month'],
        ]);

        $this->expectException(\RuntimeException::class);
        $gateway->retrievePrice('price_1');
    }

    public function test_price_retrieve_rejects_metered_prices_without_a_fixed_amount(): void
    {
        $gateway = $this->gateway([
            'id' => 'price_1',
            'object' => 'price',
            'active' => true,
            'unit_amount' => null,
            'currency' => 'eur',
            'recurring' => ['interval' => 'month'],
        ]);

        $this->expectException(\RuntimeException::class);
        $gateway->retrievePrice('price_1');
    }

    public function test_subscription_cancel_issues_a_delete_on_the_subscription(): void
    {
        $gateway = $this->gateway(['id' => 'sub_1', 'object' => 'subscription', 'status' => 'canceled']);

        $gateway->cancelSubscription('sub_1');

        $request = $this->http->requests[0];
        self::assertSame('delete', $request['method']);
        self::assertStringEndsWith('/v1/subscriptions/sub_1', $request['url']);
    }
}
