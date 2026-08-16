<?php

namespace App\Messenger\Middleware;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Handles messages inline for requests carrying `X-Playwright: 1`, so the e2e
 * suite needs no messenger consumer.
 *
 * Without this a run that forgets one does not fail in an obviously mail-shaped
 * way: the authenticated fixture verifies its user through an emailed link, so
 * login, signup, delete-account, forgot-password, the wizard, admin smoke and
 * paywall all fail together while the app returns 200.
 */
final readonly class PlaywrightSyncMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        // ReceivedStamp means a worker is already handling this; re-stamping
        // would re-route a message mid-consumption.
        if (
            null !== ($request = $this->requestStack->getCurrentRequest())
            && $request->headers->has('X-Playwright')
            && null === $envelope->last(ReceivedStamp::class)
            && null === $envelope->last(TransportNamesStamp::class)
        ) {
            $envelope = $envelope->with(new TransportNamesStamp(['sync']));
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
