<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Security\ApiTokenRateLimitKey;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Throttles the bridge's rule health reports. It runs after the firewall, so
 * the token is resolved, and keys per token because one bridge holds one token.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final readonly class RateLimitAgentBridgeRuleReports
{
    public const string ROUTE = 'api_project_bridge_rules_report';

    public function __construct(
        #[Autowire(service: 'limiter.agent_bridge_rule_reports')]
        private RateLimiterFactoryInterface $limiter,
        private ApiTokenRateLimitKey $key,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (self::ROUTE !== $request->attributes->get('_route')) {
            return;
        }

        if (!$this->limiter->create($this->key->forRequest($request))->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(message: 'Too many rule reports. Please slow down.');
        }
    }
}
