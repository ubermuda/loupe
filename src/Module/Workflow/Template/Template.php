<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

final readonly class Template
{
    /**
     * @param list<Slot>       $slots
     * @param list<Rule>       $rules
     * @param list<ManualMove> $manualMoves
     * @param list<int>        $backoffMinutes
     */
    public function __construct(
        public string $key,
        public int $version,
        public array $slots,
        public array $rules,
        public array $manualMoves,
        public array $backoffMinutes,
        public int $workTimeoutMinutes,
    ) {
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
}
