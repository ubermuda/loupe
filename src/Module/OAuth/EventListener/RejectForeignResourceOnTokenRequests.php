<?php

declare(strict_types=1);

namespace App\Module\OAuth\EventListener;

use App\Module\OAuth\Service\McpResource;
use App\Module\OAuth\Service\ResourceParameter;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers invalid_target (RFC 8707) before the bundle's token endpoint runs.
 * The grant's scope is sealed inside the code or the refresh token here, so
 * only the resource value is checked; the scope still sets the audience.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 11)]
final readonly class RejectForeignResourceOnTokenRequests
{
    public function __construct(
        private McpResource $mcpResource,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || '/oauth/token' !== $request->getPathInfo() || !$request->isMethod('POST')) {
            return;
        }

        // A multipart body never reaches getContent(), so fall back to the parsed parameter.
        $resources = ResourceParameter::values($request->getContent());
        if ([] === $resources && $request->request->has('resource')) {
            $resource = $request->request->all()['resource'];
            $resources = [\is_string($resource) ? $resource : ''];
        }

        if (!$this->mcpResource->accepts($resources, null)) {
            $event->setResponse(new JsonResponse([
                'error' => 'invalid_target',
                'error_description' => 'The resource is not one this server protects.',
            ], Response::HTTP_BAD_REQUEST, ['Cache-Control' => 'no-store']));
        }
    }
}
