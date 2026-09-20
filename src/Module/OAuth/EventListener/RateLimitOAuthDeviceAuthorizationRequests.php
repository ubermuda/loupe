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
 * Bounds the device authorization endpoint per client address. It is public,
 * and every request writes a device code row.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 12)]
final readonly class RateLimitOAuthDeviceAuthorizationRequests
{
    public function __construct(
        #[Autowire(service: 'limiter.oauth_device_authorization')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || '/oauth/device-authorization' !== $event->getRequest()->getPathInfo()) {
            return;
        }

        $key = 'ip:'.($event->getRequest()->getClientIp() ?? 'unknown');
        if (!$this->limiter->create($key)->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(message: 'Too many device authorization requests. Please slow down.');
        }
    }
}
