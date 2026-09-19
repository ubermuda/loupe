<?php

declare(strict_types=1);

namespace App\Module\OAuth\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Bounds the token endpoint per client address. It is public, and every code
 * exchange or refresh costs a key operation and several writes.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 12)]
final readonly class RateLimitOAuthTokenRequests
{
    public function __construct(
        #[Autowire(service: 'limiter.oauth_token')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || '/oauth/token' !== $event->getRequest()->getPathInfo()) {
            return;
        }

        $key = 'ip:'.($event->getRequest()->getClientIp() ?? 'unknown');
        if (!$this->limiter->create($key)->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(message: 'Too many OAuth token requests. Please slow down.');
        }
    }
}
