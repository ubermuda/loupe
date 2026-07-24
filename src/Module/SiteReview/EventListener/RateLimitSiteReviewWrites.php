<?php

declare(strict_types=1);

namespace App\Module\SiteReview\EventListener;

use App\Module\Account\Security\ApiTokenAuthenticator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Throttles write traffic on the site-review API so a leaked or scraped widget
 * token can't be used to flood comments or repeatedly (re)submit reviews. Keyed
 * on the authenticated user (falling back to client IP); safe methods and CORS
 * preflight are never limited. Runs after the firewall so the token is resolved.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final readonly class RateLimitSiteReviewWrites
{
    public function __construct(
        #[Autowire(service: 'limiter.site_review_write')]
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
        if (!str_starts_with($request->getPathInfo(), '/api/site-review') || $request->isMethodSafe()) {
            return;
        }

        if (!$this->limiter->create($this->key($request))->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(message: 'Too many site-review requests. Please slow down.');
        }
    }

    /**
     * One bucket per API credential, never per owner: every token authenticates
     * as its owning user, so an owner-keyed limit would let a visitor exhaust
     * one publicly-embedded widget token and 429 that owner's other projects
     * and their account-level clients too.
     */
    private function key(Request $request): string
    {
        $securityToken = $this->tokenStorage->getToken();

        if (null !== $securityToken && $securityToken->hasAttribute(ApiTokenAuthenticator::API_TOKEN_ID_ATTR)) {
            $apiTokenId = $securityToken->getAttribute(ApiTokenAuthenticator::API_TOKEN_ID_ATTR);
            if (is_string($apiTokenId)) {
                return 'token:'.$apiTokenId;
            }
        }

        return 'ip:'.((string) $request->getClientIp());
    }
}
