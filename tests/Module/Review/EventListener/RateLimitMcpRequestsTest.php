<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\EventListener;

use App\Module\Account\Security\ApiTokenAuthenticator;
use App\Module\Review\EventListener\RateLimitMcpRequests;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\RateLimiter\Storage\StorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * A limit of one, so the second accepted request through the same bucket is the
 * one that throws — the real configuration is sixty a minute, which no test
 * should try to exhaust.
 */
final class RateLimitMcpRequestsTest extends TestCase
{
    public function test_a_token_cannot_call_mcp_without_bound(): void
    {
        $listener = $this->listenerAuthenticatedAs('mcp-token-1');

        $listener($this->call('203.0.113.7'));

        $this->expectException(TooManyRequestsHttpException::class);
        $listener($this->call('203.0.113.7'));
    }

    public function test_one_agents_roaming_address_does_not_widen_its_allowance(): void
    {
        $listener = $this->listenerAuthenticatedAs('mcp-token-1');

        $listener($this->call('203.0.113.7'));

        // The point of keying on the token alone: a new address is the same agent.
        $this->expectException(TooManyRequestsHttpException::class);
        $listener($this->call('198.51.100.4'));
    }

    public function test_a_second_token_gets_its_own_bucket(): void
    {
        $storage = new InMemoryStorage();

        $this->listenerAuthenticatedAs('mcp-token-1', $storage)($this->call('203.0.113.7'));
        $this->listenerAuthenticatedAs('mcp-token-2', $storage)($this->call('203.0.113.7'));
        $this->addToAssertionCount(1);
    }

    public function test_safe_methods_are_never_limited(): void
    {
        $listener = $this->listenerAuthenticatedAs('mcp-token-1');

        $listener($this->call('203.0.113.7'));
        $listener($this->request('GET', '/mcp', '203.0.113.7'));
        $this->addToAssertionCount(1);
    }

    public function test_a_path_outside_the_mcp_endpoint_is_not_limited(): void
    {
        $listener = $this->listenerAuthenticatedAs('mcp-token-1');

        $listener($this->call('203.0.113.7'));
        $listener($this->request('POST', '/projects/42/mcp-token', '203.0.113.7'));
        $this->addToAssertionCount(1);
    }

    private function listenerAuthenticatedAs(string $apiTokenId, ?StorageInterface $storage = null): RateLimitMcpRequests
    {
        $securityToken = $this->createStub(TokenInterface::class);
        $securityToken->method('hasAttribute')->willReturn(true);
        $securityToken->method('getAttribute')->willReturnCallback(
            static fn (string $name): ?string => ApiTokenAuthenticator::API_TOKEN_ID_ATTR === $name ? $apiTokenId : null,
        );
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken($securityToken);

        return new RateLimitMcpRequests(
            new RateLimiterFactory(
                ['id' => 'mcp_requests', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
                $storage ?? new InMemoryStorage(),
            ),
            $tokenStorage,
        );
    }

    private function call(string $ip): RequestEvent
    {
        return $this->request('POST', '/mcp', $ip);
    }

    private function request(string $method, string $path, string $ip): RequestEvent
    {
        $request = Request::create($path, $method, server: ['REMOTE_ADDR' => $ip]);

        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
