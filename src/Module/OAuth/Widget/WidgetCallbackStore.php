<?php

declare(strict_types=1);

namespace App\Module\OAuth\Widget;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Remembers, in the popup's own session, which site each widget sign-in came
 * from. The callback page reads the entry back by the request's state, so the
 * origin it posts the code to is one the authorize step checked. Nothing in
 * the callback URL can name it.
 */
final readonly class WidgetCallbackStore
{
    private const string KEY = 'oauth_widget_callbacks';
    private const int KEEP = 10;

    public function __construct(
        private RequestStack $requests,
    ) {
    }

    public function remember(string $state, string $origin, Uuid $projectId): void
    {
        $session = $this->requests->getSession();
        $entries = $this->entries();
        unset($entries[self::keyOf($state)]);
        $entries[self::keyOf($state)] = ['origin' => $origin, 'projectId' => $projectId->toRfc4122()];
        $session->set(self::KEY, \array_slice($entries, -self::KEEP, preserve_keys: true));
    }

    /**
     * The entry for this state, removed so a reload posts nothing.
     *
     * @return array{origin: string, projectId: Uuid}|null
     */
    public function take(string $state): ?array
    {
        $entries = $this->entries();
        $entry = $entries[self::keyOf($state)] ?? null;
        unset($entries[self::keyOf($state)]);
        $this->requests->getSession()->set(self::KEY, $entries);

        return null === $entry ? null : ['origin' => $entry['origin'], 'projectId' => Uuid::fromString($entry['projectId'])];
    }

    /** @return array<string, array{origin: string, projectId: string}> */
    private function entries(): array
    {
        $entries = $this->requests->getSession()->get(self::KEY, []);
        if (!\is_array($entries)) {
            return [];
        }

        return array_filter(
            $entries,
            static fn (mixed $entry, mixed $key): bool => \is_string($key)
                && \is_array($entry)
                && \is_string($entry['origin'] ?? null)
                && \is_string($entry['projectId'] ?? null)
                && Uuid::isValid($entry['projectId']),
            \ARRAY_FILTER_USE_BOTH,
        );
    }

    private static function keyOf(string $state): string
    {
        return hash('sha256', $state);
    }
}
