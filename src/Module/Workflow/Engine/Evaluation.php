<?php

declare(strict_types=1);

namespace App\Module\Workflow\Engine;

use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\PauseView;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Template\Rule;
use App\Module\Workflow\Template\Template;

/** What one evaluation of one card reads and collects. The engine alone uses it. */
final class Evaluation
{
    /** @var list<PauseView> the pauses this evaluation applied */
    public array $pauses = [];

    /** The pause that holds the card while only release rules run. */
    public ?PauseView $holdingPause = null;

    /** True when the pass recorded a baseline instead of running the rules. */
    public bool $baselined = false;

    /** True once the pass asked for a pause, which ends it. */
    public bool $ended = false;

    /** @var array<string, true> the ids of the rules whose repair request is live */
    public array $repairing = [];

    /** @var list<array{rule: string, outcome: string, code: ?string}> */
    public array $fired = [];

    /** @param array<string, WorkflowRuleState> $states keyed by rule id */
    public function __construct(
        public readonly CardSnapshot $card,
        public readonly Project $project,
        public readonly Template $template,
        public Facts $facts,
        public array $states,
        public readonly \DateTimeImmutable $now,
    ) {
    }

    public function rule(string $id): ?Rule
    {
        foreach ($this->template->rules as $rule) {
            if ($rule->id === $id) {
                return $rule;
            }
        }

        return null;
    }

    public function applies(Rule $rule): bool
    {
        return null === $rule->slot || $rule->slot === $this->facts->card->slot;
    }
}
