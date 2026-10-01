<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service\Dev;

/** What one run of the showcase seeder did. */
final readonly class ShowcaseSeeding
{
    public function __construct(
        /** False when the project already held the showcase. */
        public bool $written,
        public bool $waitItemOpen,
        /** Loupe opens the wait item only while inbox.enabled is on. */
        public bool $inboxEnabled,
        /** The open wait items of the project after the run. */
        public int $waitItems,
        /** @var list<string> the page paths of the sync line cards this run wrote */
        public array $syncCards = [],
    ) {
    }
}
