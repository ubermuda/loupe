<?php

declare(strict_types=1);

namespace App\Module\Account\Diagnostics;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Ubermuda\HealthCheckBundle\Diagnostic;
use Ubermuda\HealthCheckBundle\DiagnosticInterface;
use Ubermuda\HealthCheckBundle\DiagnosticState;

/**
 * RateLimitApiAuthentication keys on the client address alone, so an address
 * that resolves to the proxy rather than the caller turns that limiter into one
 * bucket for the whole API.
 *
 * Request::isFromTrustedProxy() is the obvious test and it misses the shape
 * this project deploys. On App Platform the immediate peer is the platform's
 * private ingress, which the PRIVATE_SUBNETS default already trusts, while a
 * public balancer or CDN further out is not. The peer is then trusted and the
 * resolved address is still the CDN's. Comparing the resolved address against
 * the head of X-Forwarded-For covers both hops. Gating on isFromTrustedProxy()
 * would also silence a header a client sent to an instance with no proxy at
 * all, and with it the genuine single untrusted hop, which looks the same from
 * here. The warning stays, because the misconfiguration it misses collapses the
 * limiter to one bucket for every caller.
 *
 * Only X-Forwarded-For is read. `trusted_headers` does not list the RFC 7239
 * `Forwarded` header, so a proxy that sends only that one is ignored whatever
 * TRUSTED_PROXIES holds, and naming the variable would send the operator after
 * the wrong fix.
 */
final readonly class TrustedProxyCheck implements DiagnosticInterface
{
    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    #[\Override]
    public static function priority(): int
    {
        return 15;
    }

    #[\Override]
    public function __invoke(): ?Diagnostic
    {
        // The console runner has no request, and a single-host instance with
        // nothing in front of it sends no forwarding header. Neither has a
        // proxy to report on.
        $request = $this->requestStack->getMainRequest();
        if (null === $request) {
            return null;
        }

        $forwardedFor = self::headOfForwardedChain($request);
        if (null === $forwardedFor) {
            return null;
        }

        if ($forwardedFor === $request->getClientIp()) {
            return new Diagnostic('trusted_proxies', DiagnosticState::Ok, 'account.system_status.trusted_proxies.resolved');
        }

        return new Diagnostic('trusted_proxies', DiagnosticState::Warning, 'account.system_status.trusted_proxies.ignored');
    }

    /**
     * The caller as the chain reports it, normalized the way Request normalizes
     * every hop before it matches one: strip a port, skip an entry that is not
     * an address. Without the skip, garbage Symfony discards reads here as a
     * mismatch.
     */
    private static function headOfForwardedChain(Request $request): ?string
    {
        $values = array_filter($request->headers->all('x-forwarded-for'), is_string(...));

        foreach (explode(',', implode(',', $values)) as $hop) {
            $hop = trim($hop);

            // A colon at position 0 opens the `::ffff:` prefix of an
            // IPv4-mapped address, and Request reads it as no port at all.
            $colon = strpos($hop, ':');
            if (str_contains($hop, '.') && false !== $colon && 0 !== $colon) {
                $hop = substr($hop, 0, $colon);
            } elseif (str_starts_with($hop, '[')) {
                $hop = substr($hop, 1, (int) strpos($hop, ']', 1) - 1);
            }

            if (false !== filter_var($hop, \FILTER_VALIDATE_IP)) {
                return $hop;
            }
        }

        return null;
    }
}
