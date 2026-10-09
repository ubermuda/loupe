<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

use App\Module\Workflow\Condition\CardDocument;
use App\Module\Workflow\Condition\CardDocumentApproved;
use App\Module\Workflow\Condition\CardDocumentChangesRequested;
use App\Module\Workflow\Contract\ParameterValue;

final readonly class Template
{
    /**
     * @param list<TemplateCardType>          $types
     * @param list<Slot>                      $slots
     * @param list<Rule>                      $rules
     * @param list<ManualMove>                $manualMoves
     * @param list<int>                       $backoffMinutes
     * @param array<string, list<ActionCall>> $childChoices   the actions of each choice an agent states for a card it files under a parent, by `inherit` or `own`
     */
    public function __construct(
        public string $key,
        public int $version,
        public array $slots,
        public array $rules,
        public array $manualMoves,
        public array $backoffMinutes,
        public int $workTimeoutMinutes,
        public array $types,
        public string $defaultType,
        public ?WorkFailurePolicy $onWorkFailed = null,
        public array $childChoices = [],
    ) {
    }

    public function type(string $key): ?TemplateCardType
    {
        foreach ($this->types as $type) {
            if ($type->key === $key) {
                return $type;
            }
        }

        return null;
    }

    public function slot(string $key): ?Slot
    {
        foreach ($this->slots as $slot) {
            if ($slot->key === $key) {
                return $slot;
            }
        }

        return null;
    }

    /** @return list<Rule> the rules of the slot and the global rules that can act in it, in template order */
    public function rulesFor(?string $slot): array
    {
        return array_values(array_filter(
            $this->rules,
            static fn (Rule $rule): bool => (null === $rule->slot || $rule->slot === $slot)
                && (ActionType::Move !== $rule->then->type || ($rule->then->params['from'] ?? $slot) === $slot),
        ));
    }

    /** @return list<string> the document tags that the rules of the slot read, each once */
    public function documentTagsFor(?string $slot): array
    {
        $tags = [];
        foreach ($this->rulesFor($slot) as $rule) {
            foreach ($rule->when->leaves() as $leaf) {
                if ($leaf->condition instanceof CardDocument || $leaf->condition instanceof CardDocumentApproved || $leaf->condition instanceof CardDocumentChangesRequested) {
                    $tags[] = ParameterValue::string($leaf->params, 'tag');
                }
            }
        }

        return array_values(array_unique($tags));
    }
}
