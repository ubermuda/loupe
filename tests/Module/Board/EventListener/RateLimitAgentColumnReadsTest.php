<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\EventListener\RateLimitAgentColumnReads;
use App\Security\AuthenticatedCredential;
use App\Security\CredentialRateLimitKey;
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

/** A limit of one, so the second read through the same bucket is the one that throws. */
final class RateLimitAgentColumnReadsTest extends TestCase
{
    public function test_a_token_cannot_read_columns_without_bound(): void
    {
        $listener = $this->listener('agent-token-1');

        $listener($this->read('203.0.113.7'));

        $this->expectException(TooManyRequestsHttpException::class);
        $listener($this->read('198.51.100.4'));
    }

    public function test_a_second_token_gets_its_own_bucket(): void
    {
        $storage = new InMemoryStorage();

        $this->listener('agent-token-1', $storage)($this->read('203.0.113.7'));
        $this->listener('agent-token-2', $storage)($this->read('203.0.113.7'));
        $this->addToAssertionCount(1);
    }

    public function test_with_no_token_the_client_address_is_the_bucket(): void
    {
        $listener = $this->listener(null);

        $listener($this->read('203.0.113.7'));
        $listener($this->read('198.51.100.4'));

        $this->expectException(TooManyRequestsHttpException::class);
        $listener($this->read('203.0.113.7'));
    }

    public function test_another_route_is_not_limited(): void
    {
        $listener = $this->listener('agent-token-1');

        $listener($this->read('203.0.113.7'));
        $listener($this->event('api_agent_sites', '203.0.113.7'));
        $this->addToAssertionCount(1);
    }

    public function test_a_card_read_draws_on_the_same_bucket(): void
    {
        $listener = $this->listener('agent-token-1');

        $listener($this->read('203.0.113.7'));

        $this->expectException(TooManyRequestsHttpException::class);
        $listener($this->event('api_project_board_card_show', '203.0.113.7'));
    }

    private function listener(?string $apiTokenId, ?StorageInterface $storage = null): RateLimitAgentColumnReads
    {
        $tokenStorage = new TokenStorage();
        if (null !== $apiTokenId) {
            $securityToken = $this->createStub(TokenInterface::class);
            $securityToken->method('hasAttribute')->willReturn(true);
            $securityToken->method('getAttribute')->willReturnCallback(
                static fn (string $name): ?AuthenticatedCredential => AuthenticatedCredential::ATTRIBUTE === $name ? new AuthenticatedCredential($apiTokenId, ['ROLE_API_AGENT']) : null,
            );
            $tokenStorage->setToken($securityToken);
        }

        return new RateLimitAgentColumnReads(
            new RateLimiterFactory(
                ['id' => 'agent_board_columns', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
                $storage ?? new InMemoryStorage(),
            ),
            new CredentialRateLimitKey($tokenStorage),
        );
    }

    private function read(string $ip): RequestEvent
    {
        return $this->event('api_project_board_column_list', $ip);
    }

    private function event(string $route, string $ip): RequestEvent
    {
        $request = Request::create('/api/projects/anything/board/columns', Request::METHOD_GET, server: ['REMOTE_ADDR' => $ip]);
        $request->attributes->set('_route', $route);

        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
