<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\DecisionSelection;
use App\Module\Review\Repository\DecisionSelectionRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Service\DecisionBlockService;

/**
 * Re-renders one decision block as it is actually stored, for the Turbo stream
 * that answers a rejected submission.
 *
 * A decision with no stored answer yields a block with nothing checked, which
 * is the point — a first-ever answer that fails must clear the radio, not fall
 * back to some earlier one.
 */
final readonly class ShowPersistedDecisionBlockHandler
{
    public function __construct(
        private DocumentVersionRepository $documentVersions,
        private DecisionSelectionRepository $decisionSelections,
        private DecisionBlockService $decisionBlocks,
    ) {
    }

    public function __invoke(ShowPersistedDecisionBlockCommand $command): ShowPersistedDecisionBlockView
    {
        // Both come off an invalid submission on the CSRF path, so neither is
        // trusted: an unparseable id or an unknown version simply means the
        // status line goes back alone.
        if (null === $command->decisionId || null === $command->versionNumber) {
            return new ShowPersistedDecisionBlockView(null);
        }

        $version = $this->documentVersions->findByNumber($command->document, $command->versionNumber);
        if (null === $version) {
            return new ShowPersistedDecisionBlockView(null);
        }

        $blockHtml = $this->decisionBlocks->blockHtml($version->renderedHtml, $command->decisionId);
        if (null === $blockHtml) {
            return new ShowPersistedDecisionBlockView(null);
        }

        $selected = [];
        foreach ($this->decisionBlocks->extract($blockHtml) as $decision) {
            $indexes = array_values(array_filter(
                $decision->resolveIndexes(array_map(
                    static fn (DecisionSelection $selection): array => [$selection->optionLabel, $selection->optionIndex],
                    $this->decisionSelections->findByDocumentAndDecisionId($command->document, $decision->id),
                )),
                static fn (?int $index): bool => null !== $index,
            ));

            if ([] !== $indexes) {
                $selected[$decision->id] = $indexes;
            }
        }

        return new ShowPersistedDecisionBlockView(
            $this->decisionBlocks->withSelections($blockHtml, $selected, readOnly: false),
        );
    }
}
