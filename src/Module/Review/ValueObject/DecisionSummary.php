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
     * @param list<Decision>     $decisions
     * @param array<string, int> $selectedIndexByDecisionId keyed by decision id, absent when unanswered
     */
    public function __construct(
        public array $decisions,
        public array $selectedIndexByDecisionId,
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
            fn (Decision $decision): bool => isset($this->selectedIndexByDecisionId[$decision->id]),
        ));
    }

    /**
     * The list as the toolbar panel shows it: one row per block, in document order.
     *
     * The element id comes from DecisionBlockService rather than being spelled
     * out in the template, because it is the same id the rendered fieldset
     * carries and a second spelling of the prefix is a link that breaks silently.
     *
     * @return list<array{label: string, elementId: string, selected: string|null}>
     */
    public function rows(): array
    {
        return array_map(fn (Decision $decision): array => [
            'label' => $decision->label(),
            'elementId' => DecisionBlockService::blockElementId($decision->id),
            'selected' => $this->selectedOption($decision),
        ], $this->decisions);
    }

    /** The option the reviewer chose, or null while the block is unanswered. */
    public function selectedOption(Decision $decision): ?string
    {
        $index = $this->selectedIndexByDecisionId[$decision->id] ?? null;

        return null === $index ? null : $decision->optionAt($index);
    }
}
