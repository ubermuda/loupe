<?php

declare(strict_types=1);

namespace App\Module\OAuth\EventListener;

use App\Module\OAuth\Service\McpResource;
use App\Security\BearerToken;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Points an MCP client at the protected resource metadata on every 401 from
 * /mcp, whichever authenticator or entry point wrote it. Clients read the
 * header only on a 401, so the body and the status stay as they are.
 */
#[AsEventListener]
final readonly class AddMcpAuthenticationChallenge
{
    public function __construct(
        private McpResource $mcpResource,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        if (!$event->isMainRequest() || Response::HTTP_UNAUTHORIZED !== $response->getStatusCode() || 1 !== preg_match('#^/mcp(/|$)#', $request->getPathInfo())) {
            return;
        }

        $response->headers->set('WWW-Authenticate', $this->mcpResource->challenge(null === BearerToken::of($request) ? null : 'invalid_token'));
    }
}
