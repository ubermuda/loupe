<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

/** One piece of work that runs or waits for a bridge on a card: a live work request, or an open run. */
final readonly class CardLiveWorkItem
{
    /** @param ?string $kind the work kind, null for an interactive run */
    public function __construct(
        public ?string $kind,
        public \DateTimeImmutable $since,
        public bool $isRun,
    ) {
    }
}
