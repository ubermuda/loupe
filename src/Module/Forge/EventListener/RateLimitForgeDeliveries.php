<?php

declare(strict_types=1);

namespace App\Module\Forge\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Throttles every route whose defaults carry `_forge_webhook: true`. The
 * endpoints are anonymous by design, because a forge signs its body rather than
 * carrying a token the firewall can read. `_forge_webhook_key` picks the key:
 * `address` is the client address, and `hook-key` is the `hookKey` path
 * segment of a per-project hook. Neither needs the body, and reading the body
 * to find a better key would do the work the limit exists to bound.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final readonly class RateLimitForgeDeliveries
{
    public const string MARKER = '_forge_webhook';
    public const string KEYING = '_forge_webhook_key';
    public const string KEY_BY_ADDRESS = 'address';
    public const string KEY_BY_HOOK_KEY = 'hook-key';

    public function __construct(
        #[Autowire(service: 'limiter.forge_deliveries')]
        private RateLimiterFactoryInterface $addressLimiter,

        #[Autowire(service: 'limiter.forge_hook_deliveries')]
        private RateLimiterFactoryInterface $hookKeyLimiter,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (true !== $request->attributes->get(self::MARKER)) {
            return;
        }

        $keying = $request->attributes->get(self::KEYING);
        $limiter = match ($keying) {
            self::KEY_BY_ADDRESS => $this->addressLimiter->create($request->getClientIp() ?? 'unknown'),
            self::KEY_BY_HOOK_KEY => $this->hookKeyLimiter->create($this->hookKey($request->attributes->get('hookKey'))),
            default => throw new \LogicException(\sprintf('A forge webhook route must set %s to "%s" or "%s".', self::KEYING, self::KEY_BY_ADDRESS, self::KEY_BY_HOOK_KEY)),
        };

        if (!$limiter->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(message: 'Too many forge deliveries. Please slow down.');
        }
    }

    private function hookKey(mixed $hookKey): string
    {
        if (!\is_string($hookKey) || '' === $hookKey) {
            throw new \LogicException('A forge webhook route keyed by hook key must carry a hookKey parameter.');
        }

        return $hookKey;
    }
}
