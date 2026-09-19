<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\EventListener;

use App\Module\OAuth\EventListener\RateLimitOAuthTokenRequests;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class RateLimitOAuthTokenRequestsTest extends TestCase
{
    public function test_the_token_endpoint_is_throttled_per_client_address(): void
    {
        $listener = new RateLimitOAuthTokenRequests(self::factory());

        $listener($this->event('/oauth/token', '10.0.0.1'));
        $listener($this->event('/oauth/token', '10.0.0.1'));
        $listener($this->event('/oauth/token', '10.0.0.2'));

        $this->expectException(TooManyRequestsHttpException::class);
        $listener($this->event('/oauth/token', '10.0.0.1'));
    }

    public function test_other_paths_are_not_charged(): void
    {
        $listener = new RateLimitOAuthTokenRequests(self::factory());

        foreach (['/oauth/authorize', '/api/projects', '/oauth/tokens'] as $path) {
            $listener($this->event($path, '10.0.0.1'));
            $listener($this->event($path, '10.0.0.1'));
            $listener($this->event($path, '10.0.0.1'));
        }

        $listener($this->event('/oauth/token', '10.0.0.1'));
        $listener($this->event('/oauth/token', '10.0.0.1'));
        $this->expectException(TooManyRequestsHttpException::class);
        $listener($this->event('/oauth/token', '10.0.0.1'));
    }

    private static function factory(): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'oauth_token', 'policy' => 'fixed_window', 'limit' => 2, 'interval' => '1 minute'],
            new InMemoryStorage(),
        );
    }

    private function event(string $path, string $clientIp): RequestEvent
    {
        $request = Request::create($path, Request::METHOD_POST, server: ['REMOTE_ADDR' => $clientIp]);

        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
