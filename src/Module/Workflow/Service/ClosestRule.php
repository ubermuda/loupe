<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\FactKey;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\PullRequestFacts;
use App\Module\Workflow\Contract\PullRequestState;
use App\Module\Workflow\Template\Rule;

/**
 * Picks the rule with the fewest conditions left to change. A tie goes to the rule with more
 * conditions, then to the earlier rule.
 */
final readonly class ClosestRule
{
    /** @param list<Rule> $rules */
    public function find(array $rules, Facts $facts): ?ClosestRuleMatch
    {
        $closest = null;
        $closestCount = 0;
        $closestSize = 0;
        foreach ($rules as $rule) {
            [$bound, $count] = $this->bind($rule, $facts);
            $size = \count($rule->when->leaves());
            if (null === $closest || $count < $closestCount || ($count === $closestCount && $size > $closestSize)) {
                $closest = new ClosestRuleMatch($rule, $bound);
                $closestCount = $count;
                $closestSize = $size;
            }
        }

        return $closest;
    }

    /** @return array{Facts, int} the facts bound to the open pull request with the fewest false conditions, and that count */
    private function bind(Rule $rule, Facts $facts): array
    {
        $candidates = \in_array(FactKey::PullRequest, $rule->when->reads(), true)
            ? array_values(array_filter($facts->pullRequests, static fn (PullRequestFacts $pullRequest): bool => PullRequestState::Open === $pullRequest->state))
            : [];
        $best = [$facts, $rule->when->countAgainst($facts, true)];
        foreach ($candidates as $index => $candidate) {
            $bound = $facts->withPullRequest($candidate);
            $count = $rule->when->countAgainst($bound, true);
            if (0 === $index || $count < $best[1]) {
                $best = [$bound, $count];
            }
        }

        return $best;
    }
}
