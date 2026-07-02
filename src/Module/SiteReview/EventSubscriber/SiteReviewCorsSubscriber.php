<?php

declare(strict_types=1);

namespace App\Module\SiteReview\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class SiteReviewCorsSubscriber implements EventSubscriberInterface
{
    /** @return array<string, array{0: string, 1: int}> */
    public static function getSubscribedEvents(): array
    {
        // 250 runs before the firewall (8), so preflight is answered without auth.
        return [
            KernelEvents::REQUEST => ['onRequest', 250],
            KernelEvents::RESPONSE => ['onResponse', 0],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ($this->applies($request) && $request->isMethod(Request::METHOD_OPTIONS)) {
            $event->setResponse(new Response('', Response::HTTP_NO_CONTENT, $this->headers($request)));
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if ($this->applies($request)) {
            $event->getResponse()->headers->add($this->headers($request));
        }
    }

    private function applies(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/api/site-review');
    }

    /** @return array<string, string> */
    private function headers(Request $request): array
    {
        return [
            'Access-Control-Allow-Origin' => (string) $request->headers->get('Origin', '*'),
            'Access-Control-Allow-Methods' => 'GET, POST, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
            'Access-Control-Max-Age' => '3600',
            'Vary' => 'Origin',
        ];
    }
}
