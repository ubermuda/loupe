<?php

declare(strict_types=1);

namespace App\Module\Workflow\Engine;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPause;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Fact\Facts;
use App\Module\Workflow\Template\Rule;
use App\Module\Workflow\Template\Template;

/** What one evaluation of one card reads and collects. The engine alone uses it. */
final class Evaluation
{
    /** @var list<CardPause> the pauses this evaluation applied */
    public array $pauses = [];

    /** The pause that holds the card while only release rules run. */
    public ?CardPause $holdingPause = null;

    /** True once the pass asked for a pause, which ends it. */
    public bool $ended = false;

    /** @var list<array{rule: string, outcome: string, code: ?string}> */
    public array $fired = [];

    /** @param array<string, WorkflowRuleState> $states keyed by rule id */
    public function __construct(
        public readonly Card $card,
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
