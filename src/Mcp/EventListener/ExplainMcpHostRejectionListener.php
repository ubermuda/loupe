<?php

declare(strict_types=1);

namespace App\Mcp\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Makes the MCP endpoint's DNS-rebinding rejection diagnose itself.
 *
 * The MCP SDK middleware answers a Host or Origin outside the allowlist with a
 * bare "Forbidden: Invalid Host header.", which an operator pointing an agent
 * at their own instance reads as an authentication failure — nothing names the
 * variable that decided it. This rewrites the body of a rejection the SDK has
 * already made; the decision stays entirely in the middleware, so the endpoint
 * can never become more permissive than the SDK allows, and a future SDK that
 * changes its wording degrades to today's opaque message rather than to an
 * open door.
 */
#[AsEventListener]
final readonly class ExplainMcpHostRejectionListener
{
    /**
     * Route registered by the MCP bundle's route loader for the HTTP transport.
     */
    private const string MCP_ROUTE = '_mcp_endpoint';

    /**
     * Verbatim bodies of Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware,
     * mapped to the request header each one rejected. Origin is checked first
     * and short-circuits the Host check, so both have to be covered.
     */
    private const array REJECTIONS = [
        'Forbidden: Invalid Origin header.' => 'Origin', // @translation-check-ignore
        'Forbidden: Invalid Host header.' => 'Host', // @translation-check-ignore
    ];

    /**
     * The echoed value is attacker-controlled, so it is capped and stripped of
     * everything outside printable ASCII before it reaches the body or the logs.
     */
    private const int MAX_ECHO_LENGTH = 128;

    private const string EXPLANATION = "\nThe %s header of this request (%s) is not listed in MCP_ALLOWED_HOSTS, so the MCP endpoint's DNS-rebinding protection rejected it. This is not an authentication failure: add the hostname agents use to reach this instance to MCP_ALLOWED_HOSTS (comma-separated, no port) and restart the app.\n"; // @translation-check-ignore

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        if (Response::HTTP_FORBIDDEN !== $response->getStatusCode()) {
            return;
        }

        $request = $event->getRequest();
        if (self::MCP_ROUTE !== $request->attributes->get('_route')) {
            return;
        }

        // A StreamedResponse yields false here, so it never matches — the
        // rejection is always a plain buffered body.
        $header = self::REJECTIONS[(string) $response->getContent()] ?? null;
        if (null === $header) {
            return;
        }

        $response->setContent((string) $response->getContent().\sprintf(
            self::EXPLANATION,
            $header,
            $this->readable($request->headers->get($header) ?? ''),
        ));

        if ($response->headers->has('Content-Length')) {
            $response->headers->set('Content-Length', (string) \strlen((string) $response->getContent()));
        }
    }

    private function readable(string $value): string
    {
        $printable = preg_replace('/[^\x20-\x7E]/', '', $value) ?? '';

        if ('' === $printable) {
            return '(empty)';
        }

        if (\strlen($printable) > self::MAX_ECHO_LENGTH) {
            return substr($printable, 0, self::MAX_ECHO_LENGTH).'...';
        }

        return $printable;
    }
}
