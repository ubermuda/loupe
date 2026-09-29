<?php

declare(strict_types=1);

namespace App\Module\Review\ValueObject;

use App\Module\Review\Service\DecisionBlockService;

/**
 * Every decision block in a version, paired with the answer on record.
 *
 * One object rather than two parallel arrays because the page shows a count of
 * the answered ones and the toolbar panel shows which those are — both of which
 * are wrong the moment the two lists drift apart.
 */
final readonly class DecisionSummary
{
    /**
     * @param list<Decision>           $decisions
     * @param array<string, list<int>> $selectedIndexesByDecisionId keyed by decision id, absent when unanswered
     * @param array<string, string>    $notesByDecisionId           keyed by decision id, absent when no note is saved
     */
    public function __construct(
        public array $decisions,
        public array $selectedIndexesByDecisionId,
        public array $notesByDecisionId,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->decisions;
    }

    public function answeredCount(): int
    {
        return \count(array_filter(
            $this->decisions,
            $this->isAnswered(...),
        ));
    }

    /** A saved note answers a decision even when no option is chosen. */
    public function isAnswered(Decision $decision): bool
    {
        return [] !== ($this->selectedIndexesByDecisionId[$decision->id] ?? [])
            || isset($this->notesByDecisionId[$decision->id]);
    }

    /**
     * The list as the toolbar panel shows it: one row per block, in document order.
     *
     * The element id comes from DecisionBlockService rather than being spelled
     * out in the template, because it is the same id the rendered fieldset
     * carries and a second spelling of the prefix is a link that breaks silently.
     *
     * @return list<array{label: string, elementId: string, answered: bool, selected: list<string>}>
     */
    public function rows(): array
    {
        return array_map(fn (Decision $decision): array => [
            'label' => $decision->label(),
            'elementId' => DecisionBlockService::blockElementId($decision->id),
            'answered' => $this->isAnswered($decision),
            'selected' => $this->selectedOptions($decision),
        ], $this->decisions);
    }

    /**
     * The options the reviewer chose, empty while none is chosen.
     *
     * A single-choice block never holds more than one, so the panel reads both
     * kinds through this one list rather than branching on the block's type.
     *
     * @return list<string>
     */
    public function selectedOptions(Decision $decision): array
    {
        $options = [];
        foreach ($this->selectedIndexesByDecisionId[$decision->id] ?? [] as $index) {
            $option = $decision->optionAt($index);
            if (null !== $option) {
                $options[] = $option;
            }
        }

        return $options;
    }
}
