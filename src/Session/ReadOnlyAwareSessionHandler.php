<?php

declare(strict_types=1);

namespace App\Session;

use Sentry\State\HubInterface;
use Sentry\Tracing\SpanContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Picks the lock for each request when the session opens. A route marked with
 * the READ_ONLY default reads with no lock and writes nothing. It takes `true`,
 * or a Turbo-Frame name that the request header must match. Other safe
 * requests read with no lock, and every other request keeps the row lock.
 */
final class ReadOnlyAwareSessionHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    public const string READ_ONLY = '_session_read_only';

    private SessionLockMode $mode = SessionLockMode::Locking;

    public function __construct(
        public readonly \SessionHandlerInterface&\SessionUpdateTimestampHandlerInterface $locking,
        public readonly \SessionHandlerInterface&\SessionUpdateTimestampHandlerInterface $nonLocking,
        private readonly RequestStack $requestStack,
        private readonly HubInterface $hub,
    ) {
    }

    #[\Override]
    public function open(string $path, string $name): bool
    {
        $this->mode = $this->modeFor($this->requestStack->getMainRequest());

        return $this->inner()->open($path, $name);
    }

    #[\Override]
    public function read(#[\SensitiveParameter] string $id): string|false
    {
        return $this->traced(fn (): string|false => $this->inner()->read($id));
    }

    #[\Override]
    public function validateId(#[\SensitiveParameter] string $id): bool
    {
        return $this->traced(fn (): bool => $this->inner()->validateId($id));
    }

    #[\Override]
    public function write(#[\SensitiveParameter] string $id, string $data): bool
    {
        return SessionLockMode::ReadOnly === $this->mode || $this->inner()->write($id, $data);
    }

    #[\Override]
    public function updateTimestamp(#[\SensitiveParameter] string $id, string $data): bool
    {
        return SessionLockMode::ReadOnly === $this->mode || $this->inner()->updateTimestamp($id, $data);
    }

    #[\Override]
    public function destroy(#[\SensitiveParameter] string $id): bool
    {
        return SessionLockMode::ReadOnly === $this->mode || $this->inner()->destroy($id);
    }

    #[\Override]
    public function gc(int $max_lifetime): int|false
    {
        return SessionLockMode::ReadOnly === $this->mode ? 0 : $this->inner()->gc($max_lifetime);
    }

    #[\Override]
    public function close(): bool
    {
        return $this->inner()->close();
    }

    private function modeFor(?Request $request): SessionLockMode
    {
        if (null === $request) {
            return SessionLockMode::Locking;
        }

        $readOnly = $request->attributes->get(self::READ_ONLY);

        if (true === $readOnly || (is_string($readOnly) && $readOnly === $request->headers->get('Turbo-Frame'))) {
            return SessionLockMode::ReadOnly;
        }

        return $request->isMethodSafe() ? SessionLockMode::NonLocking : SessionLockMode::Locking;
    }

    private function inner(): \SessionHandlerInterface&\SessionUpdateTimestampHandlerInterface
    {
        return SessionLockMode::Locking === $this->mode ? $this->locking : $this->nonLocking;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $read
     *
     * @return T
     */
    private function traced(\Closure $read): mixed
    {
        $span = $this->hub->getSpan()?->startChild(
            SpanContext::make()
                ->setOp('session.read')
                ->setData([
                    'session.locked' => SessionLockMode::Locking === $this->mode,
                    'session.mode' => $this->mode->value,
                ]),
        );

        try {
            return $read();
        } finally {
            $span?->finish();
        }
    }
}
