<?php

declare(strict_types=1);

namespace App\Mercure\EventListener;

use App\Mercure\MercureSubscriptions;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\Authorization;

/**
 * Writes one subscriber cookie for every topic the page may listen on. It runs
 * before the bundle's SetCookieSubscriber, which copies the cookie onto the
 * response, so any second setCookie() in the same request throws.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: 8)]
final readonly class SetMercureCookieOnResponse
{
    /** A background refresh of part of a page must not narrow the topics of the whole page. */
    public const string LIVE_REFRESH_HEADER = 'X-Loupe-Live-Refresh';

    /** @param \Closure(): Authorization $authorization */
    public function __construct(
        private MercureSubscriptions $subscriptions,

        #[AutowireServiceClosure(Authorization::class)]
        private \Closure $authorization,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || $event->getRequest()->headers->has(self::LIVE_REFRESH_HEADER)) {
            return;
        }

        $topics = $this->subscriptions->allowedTopics();
        if ([] !== $topics) {
            ($this->authorization)()->setCookie($event->getRequest(), $topics);
        }
    }
}
