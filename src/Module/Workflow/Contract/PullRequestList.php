<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** Every pull request linked to the card, and the one the engine acts on while a rule names none. */
final readonly class PullRequestList
{
    /** @param list<PullRequestFacts> $pullRequests the unstacked ones first, then the oldest opened */
    public function __construct(
        public array $pullRequests,
        public ?PullRequestFacts $primary,
    ) {
    }
}
