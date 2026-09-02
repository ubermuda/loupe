<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\EventListener;

use App\Module\Account\EventListener\RateLimitApiAuthentication;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * A limit of one, so the second request through the same bucket is the one that
 * throws. The real configuration is three hundred a minute.
 */
final class RateLimitApiAuthenticationTest extends TestCase
{
    public function test_one_address_cannot_present_tokens_without_bound(): void
    {
        $listener = $this->listener();

        $listener($this->request('/mcp', '203.0.113.7'));

        $this->expectException(TooManyRequestsHttpException::class);
        $listener($this->request('/mcp', '203.0.113.7'));
    }

    public function test_the_api_firewall_shares_the_bucket_with_the_mcp_one(): void
    {
        $listener = $this->listener();

        $listener($this->request('/mcp', '203.0.113.7'));

        // One authenticator serves both firewalls, so one flood can arrive
        // through either path.
        $this->expectException(TooManyRequestsHttpException::class);
        $listener($this->request('/api/site-review/batches', '203.0.113.7'));
    }

    public function test_a_second_address_gets_its_own_bucket(): void
    {
        $listener = $this->listener();

        $listener($this->request('/mcp', '203.0.113.7'));
        $listener($this->request('/mcp', '198.51.100.4'));
        $this->addToAssertionCount(1);
    }

    /** No Authorization header means the authenticator never runs, so no record is written. */
    public function test_a_request_with_no_bearer_token_is_not_limited(): void
    {
        $listener = $this->listener();

        $listener($this->request('/mcp', '203.0.113.7'));
        $listener($this->request('/mcp', '203.0.113.7', bearer: false));
        $this->addToAssertionCount(1);
    }

    public function test_a_path_outside_the_token_firewalls_is_not_limited(): void
    {
        $listener = $this->listener();

        $listener($this->request('/mcp', '203.0.113.7'));
        $listener($this->request('/projects/42', '203.0.113.7'));
        $this->addToAssertionCount(1);
    }

    /** Safe methods count too: a GET with a bad token records a failure like any other. */
    public function test_a_safe_method_counts_against_the_allowance(): void
    {
        $listener = $this->listener();

        $listener($this->request('/api/site-review/comments', '203.0.113.7', method: Request::METHOD_GET));

        $this->expectException(TooManyRequestsHttpException::class);
        $listener($this->request('/api/site-review/comments', '203.0.113.7', method: Request::METHOD_GET));
    }

    private function listener(?StorageInterface $storage = null): RateLimitApiAuthentication
    {
        return new RateLimitApiAuthentication(
            new RateLimiterFactory(
                ['id' => 'api_authentication', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
                $storage ?? new InMemoryStorage(),
            ),
        );
    }

    private function request(
        string $path,
        string $ip,
        string $method = Request::METHOD_POST,
        bool $bearer = true,
    ): RequestEvent {
        $server = ['REMOTE_ADDR' => $ip];
        if ($bearer) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer whatever-was-presented';
        }

        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create($path, $method, server: $server),
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
