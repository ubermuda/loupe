<?php

declare(strict_types=1);

namespace App\Module\Bridge\EventListener;

use App\Security\CredentialRateLimitKey;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Throttles the bridge's run reports. It runs after the firewall, so the token
 * is resolved, and keys per token because one bridge holds one token.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final readonly class RateLimitWorkerRunReports
{
    /** The routes share one budget, because one bridge sends all of them. */
    public const array ROUTES = [
        'api_project_worker_run_report',
        'api_project_worker_run_state_report',
        'api_bridge_runs_report',
    ];

    public function __construct(
        #[Autowire(service: 'limiter.agent_worker_runs')]
        private RateLimiterFactoryInterface $limiter,
        private CredentialRateLimitKey $key,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!\in_array($request->attributes->get('_route'), self::ROUTES, true)) {
            return;
        }

        if (!$this->limiter->create($this->key->forRequest($request))->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(message: 'Too many worker run reports. Please slow down.');
        }
    }
}
