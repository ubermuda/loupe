<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\EventListener;

use App\Module\Account\Security\ApiTokenAuthenticator;
use App\Module\SiteReview\EventListener\RateLimitSiteReviewWrites;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * A limit of one, so the second accepted request through the same bucket is the
 * one that throws — the real configuration is sixty a minute, which no test
 * should try to exhaust.
 */
final class RateLimitSiteReviewWritesTest extends TestCase
{
    public function test_one_visitor_cannot_spend_the_whole_projects_allowance(): void
    {
        $listener = $this->listenerAuthenticatedAs('widget-token-1');

        $listener($this->write('203.0.113.7'));

        // Same visitor, same token: their own allowance is gone.
        $this->expectException(TooManyRequestsHttpException::class);
        $listener($this->write('203.0.113.7'));
    }

    public function test_a_second_visitor_on_the_same_token_still_gets_through(): void
    {
        $listener = $this->listenerAuthenticatedAs('widget-token-1');

        $listener($this->write('203.0.113.7'));

        // The point of keying on both: one abusive reviewer must not be able to
        // deny every other reviewer on the same project.
        $listener($this->write('198.51.100.4'));
        $this->addToAssertionCount(1);
    }

    public function test_safe_methods_are_never_limited(): void
    {
        $listener = $this->listenerAuthenticatedAs('widget-token-1');

        $listener($this->write('203.0.113.7'));
        $listener($this->request('GET', '/api/site-review/comments', '203.0.113.7'));
        $this->addToAssertionCount(1);
    }

    private function listenerAuthenticatedAs(string $apiTokenId): RateLimitSiteReviewWrites
    {
        $securityToken = $this->createStub(TokenInterface::class);
        $securityToken->method('hasAttribute')->willReturn(true);
        $securityToken->method('getAttribute')->willReturnCallback(
            static fn (string $name): ?string => ApiTokenAuthenticator::API_TOKEN_ID_ATTR === $name ? $apiTokenId : null,
        );
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken($securityToken);

        return new RateLimitSiteReviewWrites(
            new RateLimiterFactory(
                ['id' => 'site_review_write', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
                new InMemoryStorage(),
            ),
            $tokenStorage,
        );
    }

    private function write(string $ip): RequestEvent
    {
        return $this->request('POST', '/api/site-review/comments', $ip);
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
