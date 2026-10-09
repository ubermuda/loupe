<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

use App\Module\Workflow\Contract\ReadsDocumentTag;

final readonly class Template
{
    /**
     * @param list<TemplateCardType> $types
     * @param list<Slot>             $slots
     * @param list<Rule>             $rules
     * @param list<ManualMove>       $manualMoves
     * @param list<int>              $backoffMinutes
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
                && (null === $rule->then->from || $rule->then->from === $slot),
        ));
    }

    /** @return list<string> the document tags that the rules of the slot read, each once */
    public function documentTagsFor(?string $slot): array
    {
        $tags = [];
        foreach ($this->rulesFor($slot) as $rule) {
            foreach ($rule->when->leaves() as $leaf) {
                $tag = $leaf->condition instanceof ReadsDocumentTag ? $leaf->condition->documentTag($leaf->params) : null;
                if (null !== $tag) {
                    $tags[] = $tag;
                }
            }
        }

        return array_values(array_unique($tags));
    }
}
