<?php

declare(strict_types=1);

namespace App\Module\OAuth\Security;

use App\Module\OAuth\Service\McpResource;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

/** A valid token without the mcp scope: the client may ask the user for it (RFC 6750). */
final readonly class McpAccessDeniedHandler implements AccessDeniedHandlerInterface
{
    public function __construct(
        private McpResource $mcpResource,
    ) {
    }

    #[\Override]
    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        return new JsonResponse(['error' => 'insufficient_scope'], Response::HTTP_FORBIDDEN, [
            'WWW-Authenticate' => $this->mcpResource->challenge('insufficient_scope'),
        ]);
    }
}
