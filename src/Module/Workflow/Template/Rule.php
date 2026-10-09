<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

use App\Module\Workflow\Contract\ActionContext;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Expression\Expression;

final readonly class Rule
{
    /** @param ?string $slot a slot key, '@backlog', '@terminal', or null for a rule that applies in every column */
    public function __construct(
        public string $id,
        public ?string $slot,
        public Expression $when,
        public ActionCall $then,
        public RuleOrigin $origin = RuleOrigin::Template,
    ) {
    }

    /** What the action of the rule reads when it runs on the card. An ask hands over the labels of its options. */
    public function context(CardSnapshot $card, Facts $facts, int $fires): ActionContext
    {
        $params = $this->then->params;
        if ([] !== $this->then->options) {
            $params['options'] = array_map(static fn (AskOption $option): string => $option->label, $this->then->options);
        }

        return new ActionContext($card, $this->id, RuleOrigin::App === $this->origin, $params, $facts, $fires);
    }
}
