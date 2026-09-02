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
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * A limit of one, so the request that follows a single charged failure is the
 * one that throws. The real configuration is three hundred a minute.
 */
final class RateLimitApiAuthenticationTest extends TestCase
{
    public function test_one_address_cannot_fail_authentication_without_bound(): void
    {
        $listener = $this->listener();

        $listener($this->arriving('/mcp', '203.0.113.7'));
        $listener->chargeFailure($this->failure('/mcp', '203.0.113.7'));

        $this->expectException(TooManyRequestsHttpException::class);
        $listener($this->arriving('/mcp', '203.0.113.7'));
    }

    /**
     * The whole point of the split: the widget token authenticates on every page
     * view of a customer's site, and those reads must never fill the bucket.
     */
    public function test_a_request_that_never_fails_spends_nothing(): void
    {
        $listener = $this->listener();

        for ($i = 0; $i < 10; ++$i) {
            $listener($this->arriving('/api/site-review/comments', '203.0.113.7', method: Request::METHOD_GET));
        }

        $this->addToAssertionCount(1);
    }

    public function test_the_api_firewall_shares_the_bucket_with_the_mcp_one(): void
    {
        $listener = $this->listener();

        // One authenticator serves both firewalls, so one flood can arrive
        // through either path.
        $listener->chargeFailure($this->failure('/mcp', '203.0.113.7', firewall: 'mcp'));

        $this->expectException(TooManyRequestsHttpException::class);
        $listener($this->arriving('/api/site-review/batches', '203.0.113.7'));
    }

    public function test_a_failure_on_the_interactive_firewall_is_not_charged(): void
    {
        $listener = $this->listener();

        $listener->chargeFailure($this->failure('/login', '203.0.113.7', firewall: 'main'));
        $listener($this->arriving('/mcp', '203.0.113.7'));
        $this->addToAssertionCount(1);
    }

    public function test_a_second_address_gets_its_own_bucket(): void
    {
        $listener = $this->listener();

        $listener->chargeFailure($this->failure('/mcp', '203.0.113.7'));
        $listener($this->arriving('/mcp', '198.51.100.4'));
        $this->addToAssertionCount(1);
    }

    /** No Authorization header means the authenticator never runs, so no record is written. */
    public function test_a_request_with_no_bearer_token_is_not_limited(): void
    {
        $listener = $this->listener();

        $listener->chargeFailure($this->failure('/mcp', '203.0.113.7'));
        $listener($this->arriving('/mcp', '203.0.113.7', bearer: false));
        $this->addToAssertionCount(1);
    }

    public function test_a_path_outside_the_token_firewalls_is_not_limited(): void
    {
        $listener = $this->listener();

        $listener->chargeFailure($this->failure('/mcp', '203.0.113.7'));
        $listener($this->arriving('/projects/42', '203.0.113.7'));
        $this->addToAssertionCount(1);
    }

    /** Safe methods count too: a GET with a bad token records a failure like any other. */
    public function test_a_safe_method_counts_against_the_allowance(): void
    {
        $listener = $this->listener();

        $listener->chargeFailure($this->failure('/api/site-review/comments', '203.0.113.7', method: Request::METHOD_GET));

        $this->expectException(TooManyRequestsHttpException::class);
        $listener($this->arriving('/api/site-review/comments', '203.0.113.7', method: Request::METHOD_GET));
    }

    /**
     * Production runs sliding_window, and the two policies take different
     * branches on a zero-token consume. Ten peeks against an allowance of one
     * pass only if the peek spends nothing, and the charge below proves the
     * limiter is live rather than inert.
     */
    public function test_the_peek_spends_nothing_under_the_production_policy(): void
    {
        $listener = $this->listener('sliding_window');

        for ($i = 0; $i < 10; ++$i) {
            $listener($this->arriving('/api/site-review/sites', '203.0.113.7', method: Request::METHOD_GET));
        }

        $listener->chargeFailure($this->failure('/api/site-review/sites', '203.0.113.7', method: Request::METHOD_GET));

        $this->expectException(TooManyRequestsHttpException::class);
        $listener($this->arriving('/api/site-review/sites', '203.0.113.7', method: Request::METHOD_GET));
    }

    private function listener(string $policy = 'fixed_window'): RateLimitApiAuthentication
    {
        return new RateLimitApiAuthentication(
            new RateLimiterFactory(
                ['id' => 'api_authentication', 'policy' => $policy, 'limit' => 1, 'interval' => '1 minute'],
                new InMemoryStorage(),
            ),
        );
    }

    private function arriving(
        string $path,
        string $ip,
        string $method = Request::METHOD_POST,
        bool $bearer = true,
    ): RequestEvent {
        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $this->request($path, $ip, $method, $bearer),
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    private function failure(
        string $path,
        string $ip,
        string $method = Request::METHOD_POST,
        string $firewall = 'api',
    ): LoginFailureEvent {
        return new LoginFailureEvent(
            new AuthenticationException('Invalid API token.'),
            $this->createStub(AuthenticatorInterface::class),
            $this->request($path, $ip, $method),
            null,
            $firewall,
        );
    }

    private function request(string $path, string $ip, string $method, bool $bearer = true): Request
    {
        $server = ['REMOTE_ADDR' => $ip];
        if ($bearer) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer whatever-was-presented';
        }

        return Request::create($path, $method, server: $server);
    }
}
