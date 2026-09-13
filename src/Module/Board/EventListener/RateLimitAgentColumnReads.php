<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Account\Security\ApiTokenAuthenticator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Throttles the bridge's column reads. The route is a GET, so unlike the write
 * limiters this one also counts safe methods. It runs after the firewall, so
 * the token is resolved, and keys per token because one bridge holds one token.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final readonly class RateLimitAgentColumnReads
{
    public const string ROUTE = 'api_agent_project_columns';

    public function __construct(
        #[Autowire(service: 'limiter.agent_board_columns')]
        private RateLimiterFactoryInterface $limiter,
        private TokenStorageInterface $tokenStorage,
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

        if (!$this->limiter->create($this->key((string) $request->getClientIp()))->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(message: 'Too many column reads. Please slow down.');
        }
    }

    private function key(string $clientIp): string
    {
        $securityToken = $this->tokenStorage->getToken();
        if (null !== $securityToken && $securityToken->hasAttribute(ApiTokenAuthenticator::API_TOKEN_ID_ATTR)) {
            $apiTokenId = $securityToken->getAttribute(ApiTokenAuthenticator::API_TOKEN_ID_ATTR);
            if (\is_string($apiTokenId)) {
                return 'token:'.$apiTokenId;
            }
        }

        return 'ip:'.$clientIp;
    }
}
