<?php

declare(strict_types=1);

namespace App\Module\Review\EventListener;

use App\Security\ApiTokenRateLimitKey;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Throttles the MCP endpoint so a ROLE_API_MCP bearer token can't call
 * document_create or document_revise without bound. Safe methods are never
 * limited; runs after the firewall so the token is resolved.
 *
 * MCP streams every JSON-RPC message over POST, so the handshake shares this
 * bucket and the usable tool-call budget is below the configured limit.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final readonly class RateLimitMcpRequests
{
    public function __construct(
        #[Autowire(service: 'limiter.mcp_requests')]
        private RateLimiterFactoryInterface $limiter,
        private ApiTokenRateLimitKey $key,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->isMcpEndpoint($request->getPathInfo()) || $request->isMethodSafe()) {
            return;
        }

        // Per token, with no address component, the opposite of the widget
        // limiter: an MCP token is held by one agent, and an address would
        // split one roaming agent's allowance and merge two agents behind a NAT.
        if (!$this->limiter->create($this->key->forRequest($request))->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(message: 'Too many MCP requests. Please slow down.');
        }
    }

    private function isMcpEndpoint(string $path): bool
    {
        return '/mcp' === $path || str_starts_with($path, '/mcp/');
    }
}
