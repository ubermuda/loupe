<?php

declare(strict_types=1);

namespace App\Module\Review\EventListener;

use App\Module\Account\Security\ApiTokenAuthenticator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

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
        private TokenStorageInterface $tokenStorage,
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

        if (!$this->limiter->create($this->key($request))->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(message: 'Too many MCP requests. Please slow down.');
        }
    }

    /**
     * Per token, with no address component — the opposite of the widget limiter,
     * because an MCP token is held by one agent rather than shared by every
     * visitor to a page. Adding the client address would split that one agent's
     * allowance across roaming addresses and merge two agents behind one NAT.
     */
    private function key(Request $request): string
    {
        $securityToken = $this->tokenStorage->getToken();

        if (null !== $securityToken && $securityToken->hasAttribute(ApiTokenAuthenticator::API_TOKEN_ID_ATTR)) {
            $apiTokenId = $securityToken->getAttribute(ApiTokenAuthenticator::API_TOKEN_ID_ATTR);
            if (is_string($apiTokenId)) {
                return 'token:'.$apiTokenId;
            }
        }

        return 'ip:'.((string) $request->getClientIp());
    }

    private function isMcpEndpoint(string $path): bool
    {
        return '/mcp' === $path || str_starts_with($path, '/mcp/');
    }
}
