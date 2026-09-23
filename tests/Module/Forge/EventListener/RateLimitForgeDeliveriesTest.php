<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\EventListener;

use App\Module\Forge\EventListener\RateLimitForgeDeliveries;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/** A limit of one per limiter, so the second delivery through the same bucket is the one that throws. */
final class RateLimitForgeDeliveriesTest extends TestCase
{
    public function test_a_route_without_the_marker_is_not_limited(): void
    {
        $listener = $this->listener();

        $listener($this->event([], '203.0.113.7'));
        $listener($this->event([], '203.0.113.7'));
        $this->addToAssertionCount(1);
    }

    public function test_an_address_route_is_keyed_by_the_client_address(): void
    {
        $listener = $this->listener();
        $address = ['_forge_webhook' => true, '_forge_webhook_key' => 'address'];

        $listener($this->event($address, '203.0.113.7'));
        $listener($this->event($address, '198.51.100.4'));

        $this->expectException(TooManyRequestsHttpException::class);
        $listener($this->event($address, '203.0.113.7'));
    }

    public function test_a_hook_key_route_is_keyed_by_the_hook_key_across_addresses(): void
    {
        $listener = $this->listener();

        $listener($this->event($this->hook('key-one'), '203.0.113.7'));
        $listener($this->event($this->hook('key-two'), '203.0.113.7'));

        $this->expectException(TooManyRequestsHttpException::class);
        $listener($this->event($this->hook('key-one'), '198.51.100.4'));
    }

    public function test_the_two_keyings_use_separate_limiters(): void
    {
        $listener = $this->listener();

        $listener($this->event(['_forge_webhook' => true, '_forge_webhook_key' => 'address'], '203.0.113.7'));
        $listener($this->event($this->hook('key-one'), '203.0.113.7'));
        $this->addToAssertionCount(1);
    }

    public function test_a_hook_key_route_without_a_hook_key_is_a_misconfiguration(): void
    {
        $this->expectException(\LogicException::class);
        $this->listener()($this->event(['_forge_webhook' => true, '_forge_webhook_key' => 'hook-key'], '203.0.113.7'));
    }

    public function test_an_unknown_keying_is_a_misconfiguration(): void
    {
        $this->expectException(\LogicException::class);
        $this->listener()($this->event(['_forge_webhook' => true, '_forge_webhook_key' => 'token'], '203.0.113.7'));
    }

    /** @return array<string, mixed> */
    private function hook(string $hookKey): array
    {
        return ['_forge_webhook' => true, '_forge_webhook_key' => 'hook-key', 'hookKey' => $hookKey];
    }

    private function listener(): RateLimitForgeDeliveries
    {
        return new RateLimitForgeDeliveries(
            new RateLimiterFactory(['id' => 'forge_deliveries', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'], new InMemoryStorage()),
            new RateLimiterFactory(['id' => 'forge_hook_deliveries', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'], new InMemoryStorage()),
        );
    }

    /** @param array<string, mixed> $attributes */
    private function event(array $attributes, string $ip): RequestEvent
    {
        $request = Request::create('/webhooks/forge/anything', Request::METHOD_POST, server: ['REMOTE_ADDR' => $ip]);
        $request->attributes->add($attributes);

        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
