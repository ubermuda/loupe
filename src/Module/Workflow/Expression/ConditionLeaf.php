<?php

declare(strict_types=1);

namespace App\Module\Workflow\Expression;

use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\PullRequestList;
use App\Module\Workflow\Contract\Unreadable;
use App\Module\Workflow\Contract\UnreadableKind;

final readonly class ConditionLeaf extends Expression
{
    /** @param array<string, mixed> $params */
    public function __construct(
        public Condition $condition,
        public array $params,
    ) {
    }

    #[\Override]
    public function evaluate(Facts $facts): bool
    {
        return $this->condition->evaluate($facts, $this->params);
    }

    /** A facts class that no provider gives counts as a failed source, so the rule waits instead of crashing. */
    #[\Override]
    public function unreadable(Facts $facts): ?Unreadable
    {
        foreach ($this->reads() as $key) {
            // The pull request a rule acts on comes from the list of pull requests, so the rule waits while the list is unreadable.
            if (EngineFact::PullRequest === $key) {
                $key = PullRequestList::class;
            }
            if (!\is_string($key)) {
                continue;
            }
            if (!\array_key_exists($key, $facts->provided)) {
                return new Unreadable(UnreadableKind::Failed, $this->condition::source(), new \LogicException(\sprintf('No fact provider gives "%s".', $key)));
            }
            $unreadable = $facts->unreadable($key);
            if (null !== $unreadable) {
                return $unreadable;
            }
        }

        return null;
    }

    #[\Override]
    public function leafAgainst(Facts $facts, bool $wanted): ?BlockingLeaf
    {
        return $this->evaluate($facts) === $wanted ? null : new BlockingLeaf($this, negated: !$wanted);
    }

    #[\Override]
    public function reads(): array
    {
        return $this->condition->reads($this->params);
    }

    #[\Override]
    public function leaves(): array
    {
        return [$this];
    }

    #[\Override]
    public function missingKeys(): array
    {
        return [];
    }
}
