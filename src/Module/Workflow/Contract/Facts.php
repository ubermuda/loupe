<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** What the engine knows about one card at one moment. Conditions read it and nothing else. */
final readonly class Facts
{
    /** @param list<PullRequestFacts> $pullRequests every pull request linked to the card */
    public function __construct(
        public \DateTimeImmutable $now,
        public CardFacts $card,
        public ?PullRequestFacts $pullRequest,
        public array $pullRequests,
        public RunFacts $run,
    ) {
    }
}
