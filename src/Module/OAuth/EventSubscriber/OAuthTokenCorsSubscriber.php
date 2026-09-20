<?php

declare(strict_types=1);

namespace App\Module\OAuth\EventSubscriber;

use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Lets the site-review widget call the token endpoint from the page it runs
 * on. Only an origin some project lists as an allowed site gets the header,
 * and never with credentials: the endpoint reads no cookie.
 */
final readonly class OAuthTokenCorsSubscriber implements EventSubscriberInterface
{
    private const string PATH = '/oauth/token';

    public function __construct(
        private ProjectRepository $projects,
    ) {
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function getSubscribedEvents(): array
    {
        // Above the firewall and the token rate limit, so a preflight costs neither.
        return [
            KernelEvents::REQUEST => ['onRequest', 250],
            KernelEvents::RESPONSE => ['onResponse', 0],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ($event->isMainRequest() && self::PATH === $request->getPathInfo() && $request->isMethod(Request::METHOD_OPTIONS)) {
            $event->setResponse(new Response('', Response::HTTP_NO_CONTENT, $this->headers($request)));
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if ($event->isMainRequest() && self::PATH === $request->getPathInfo()) {
            $event->getResponse()->headers->add($this->headers($request));
        }
    }

    /** @return array<string, string> */
    private function headers(Request $request): array
    {
        $headers = ['Vary' => 'Origin'];
        $origin = $request->headers->get('Origin');
        if (null === $origin || !$this->projects->anyAllowsOrigin($origin)) {
            return $headers;
        }

        return [
            ...$headers,
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type',
            'Access-Control-Max-Age' => '3600',
        ];
    }
}
