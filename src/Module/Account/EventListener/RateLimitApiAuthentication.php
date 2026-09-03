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
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Bounds failed API-token authentications on the two token firewalls, ^/api and
 * ^/mcp, so a flood of rejected tokens cannot fill the audit table. Each such
 * failure writes a durable record that the retention window keeps.
 *
 * Peek and charge are split, and both halves live here so the bucket key cannot
 * drift between them. An arriving request only reads the bucket. A token is
 * spent when an authentication fails, which is exactly when a record is written.
 * The site-review widget token rides on every page view of a customer's site, so
 * charging a successful read would throttle a whole NAT of unrelated visitors.
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
    /** The stateless firewalls ApiTokenAuthenticator serves, named as in security.yaml. */
    private const array TOKEN_FIREWALLS = ['api', 'mcp'];

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

        // consume(0) reads the bucket without spending a token, but the RateLimit
        // it returns is accepted unconditionally, so read the remaining count.
        // Between this peek and the charge below, concurrent failures can push
        // the count a little past the limit. That is the price of not charging
        // the successful requests that are nearly all of the bearer traffic.
        if ($this->limiter->create($this->key($request))->consume(0)->getRemainingTokens() < 1) {
            throw new TooManyRequestsHttpException(message: 'Too many API authentication attempts. Please slow down.');
        }
    }

    /**
     * ApiTokenAuthenticator::onAuthenticationFailure() writes the record this
     * limit exists to bound, and the authenticator manager calls it immediately
     * before it dispatches this event.
     */
    #[AsEventListener]
    public function chargeFailure(LoginFailureEvent $event): void
    {
        if (!in_array($event->getFirewallName(), self::TOKEN_FIREWALLS, true)) {
            return;
        }

        $this->limiter->create($this->key($event->getRequest()))->consume();
    }

    /**
     * The client address alone, where the two limiters that run after the
     * firewall key per token. No token is resolved yet at the peek, and a
     * rejected one is chosen by whoever sent it, so keying on it would hand an
     * attacker a fresh allowance for every guess.
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
