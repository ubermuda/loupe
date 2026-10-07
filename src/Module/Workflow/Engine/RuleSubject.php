<?php

declare(strict_types=1);

namespace App\Module\Workflow\Engine;

use App\Module\Workflow\Contract\FactKey;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\PullRequestFacts;
use App\Module\Workflow\Contract\PullRequestState;
use App\Module\Workflow\Template\Rule;
use Symfony\Component\Uid\Uuid;

/**
 * Picks the pull request a rule acts on. A rule that reads the pull request takes the first open one,
 * in the order of the facts, that makes it true, and else the first open one.
 */
final readonly class RuleSubject
{
    public function bind(Rule $rule, Facts $facts): BoundRule
    {
        $readable = null === $rule->when->unreadable($facts);
        if (!\in_array(FactKey::PullRequest, $rule->when->reads(), true)) {
            return new BoundRule($facts, $facts->pullRequest?->id, $readable && $rule->when->evaluate($facts), false);
        }

        $candidates = array_values(array_filter($facts->pullRequests, static fn (PullRequestFacts $pullRequest): bool => PullRequestState::Open === $pullRequest->state));
        if ([] === $candidates) {
            return new BoundRule($facts, $facts->pullRequest?->id, $readable && $rule->when->evaluate($facts), true);
        }
        foreach ($candidates as $candidate) {
            $bound = $facts->withPullRequest($candidate);
            if ($readable && $rule->when->evaluate($bound)) {
                return new BoundRule($bound, $candidate->id, true, true);
            }
        }

        return new BoundRule($facts->withPullRequest($candidates[0]), $candidates[0]->id, false, true);
    }

    /** The facts a rule pause reads its until on: the pull request it paused, while the card still links it, else the bound one. */
    public function paused(Rule $rule, Facts $facts, ?Uuid $stored): Facts
    {
        $bound = $this->bind($rule, $facts);
        $paused = !$bound->binds || null === $stored ? null
            : array_find($facts->pullRequests, static fn (PullRequestFacts $pullRequest): bool => true === $stored->equals($pullRequest->id));

        return null === $paused ? $bound->facts : $facts->withPullRequest($paused);
    }

    /** The facts of the stored subject, while the card still links it, else null. A refill never reads another pull request. */
    public function stored(Facts $facts, ?Uuid $stored): ?Facts
    {
        $subject = null === $stored ? null : array_find($facts->pullRequests, static fn (PullRequestFacts $pullRequest): bool => true === $stored->equals($pullRequest->id));

        return null === $subject ? null : $facts->withPullRequest($subject);
    }
}
