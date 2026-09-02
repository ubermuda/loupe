<?php

declare(strict_types=1);

namespace App\Module\Account\EventListener;

use App\Module\Account\Security\ApiTokenAuthenticator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Bounds bearer traffic to the two token firewalls, ^/api and ^/mcp, so an
 * unauthenticated flood cannot fill the audit table. Every failed API-token
 * authentication writes a durable record that the retention window keeps.
 *
 * The priority is the whole point. Symfony's Firewall subscribes to
 * kernel.request at 8, and a failed authentication answers through
 * RequestEvent::setResponse(), which stops propagation. A listener below 8, as
 * both other API limiters are, never runs on the requests that write these
 * records. 12 is above the firewall and below the router at 32.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 12)]
final readonly class RateLimitApiAuthentication
{
    public function __construct(
        #[Autowire(service: 'limiter.api_authentication')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        // The security ExceptionListener renders through a sub-request, which
        // re-enters kernel.request.
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->isTokenFirewallPath($request->getPathInfo()) || !ApiTokenAuthenticator::carriesBearerToken($request)) {
            return;
        }

        if (!$this->limiter->create($this->key($request))->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(message: 'Too many API authentication attempts. Please slow down.');
        }
    }

    /**
     * The client address alone, where the two limiters that run after the
     * firewall key per token. No token is resolved yet here, and a rejected one
     * is chosen by whoever sent it, so keying on it would hand an attacker a
     * fresh allowance for every guess.
     *
     * The cost is that one address covers every client behind one NAT.
     */
    private function key(Request $request): string
    {
        return 'ip:'.($request->getClientIp() ?? 'unknown');
    }

    /** The firewall patterns, rather than the narrower path each endpoint serves. */
    private function isTokenFirewallPath(string $path): bool
    {
        return str_starts_with($path, '/api') || str_starts_with($path, '/mcp');
    }
}
