<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

/** What the site review rules read about one card: the verdicts to send and the check of each open pull request. */
final readonly class SiteReviewFacts
{
    /**
     * @param list<string>               $pendingDeliveryIds
     * @param array<string, CheckWanted> $checks             keyed by forge pull request id
     * @param bool                       $checkOptedIn       whether the project lets Loupe post the check
     */
    public function __construct(
        public array $pendingDeliveryIds,
        public array $checks,
        public bool $checkOptedIn = false,
    ) {
    }
}
