<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Security\CredentialRateLimitKey;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Throttles the bridge's board reads, the columns and one card, in one bucket.
 * The routes are GETs, so unlike the write limiters this one also counts safe
 * methods. It runs after the firewall, so the token is resolved, and keys per
 * token because one bridge holds one token.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final readonly class RateLimitAgentColumnReads
{
    public const array ROUTES = ['api_project_board_column_list', 'api_project_board_card_show'];

    public function __construct(
        #[Autowire(service: 'limiter.agent_board_columns')]
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
            throw new TooManyRequestsHttpException(message: 'Too many column reads. Please slow down.');
        }
    }
}
