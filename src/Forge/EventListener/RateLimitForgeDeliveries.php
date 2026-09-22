<?php

declare(strict_types=1);

namespace App\Forge\EventListener;

use App\Forge\Controller\ForgeWebhookController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Throttles forge deliveries. The endpoint is anonymous by design, because a
 * forge signs its body rather than carrying a token the firewall can read, so
 * the key is the client address. That is the only identity available before
 * the adapter reads the body, and reading the body to find a better one would
 * do the work the limit exists to bound.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final readonly class RateLimitForgeDeliveries
{
    public function __construct(
        #[Autowire(service: 'limiter.forge_deliveries')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (ForgeWebhookController::ROUTE !== $request->attributes->get('_route')) {
            return;
        }

        $key = ForgeWebhookController::ROUTE.':'.($request->getClientIp() ?? 'unknown');
        if (!$this->limiter->create($key)->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(message: 'Too many forge deliveries. Please slow down.');
        }
    }
}
