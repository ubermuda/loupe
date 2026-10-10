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
        /** The page path of the card with an outdated approval, when this run wrote it. */
        public ?string $outdatedCard = null,
    ) {
    }
}
