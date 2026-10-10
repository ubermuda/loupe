<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

/** The open pull requests of one card whose approval misses the head, with no notice for that head yet. */
final readonly class StaleApprovalFacts
{
    /** @param array<string, string> $heads the head of each such pull request, keyed and sorted by forge pull request id */
    public function __construct(
        public array $heads,
    ) {
    }
}
