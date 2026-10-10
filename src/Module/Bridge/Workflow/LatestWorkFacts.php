<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

use App\Module\Bridge\ValueObject\WorkRequestState;

/** The state, kind and reason of the latest work request of the card, in any state. Every field is null when the card has none. */
final readonly class LatestWorkFacts
{
    public function __construct(
        public ?WorkRequestState $state,
        public ?string $kind,
        public ?string $reason,
    ) {
    }
}
