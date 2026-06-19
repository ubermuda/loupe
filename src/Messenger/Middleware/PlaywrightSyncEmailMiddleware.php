<?php

namespace App\Messenger\Middleware;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Forces email messages to the sync transport when a request carries X-Playwright: 1.
 *
 * Playwright sends this header (via extraHTTPHeaders in playwright.config.ts) on every
 * request. This middleware intercepts SendEmailMessage dispatches during those requests
 * and stamps them with the sync transport, so verification and reset emails arrive in
 * Mailpit before the test polls for them — without altering the async transport for
 * normal dev usage.
 */
final readonly class PlaywrightSyncEmailMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $request = $this->requestStack->getCurrentRequest();

        if (
            null !== $request
            && $request->headers->has('X-Playwright')
            && $envelope->getMessage() instanceof SendEmailMessage
            && null === $envelope->last(TransportNamesStamp::class)
        ) {
            $envelope = $envelope->with(new TransportNamesStamp(['sync']));
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
