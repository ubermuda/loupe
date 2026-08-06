<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

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
            $selection = $this->decisionSelections->findOneByDocumentAndDecisionId($command->document, $decision->id);
            $index = null === $selection ? null : $decision->resolveIndex($selection->optionLabel, $selection->optionIndex);
            if (null !== $index) {
                $selected[$decision->id] = $index;
            }
        }

        return new ShowPersistedDecisionBlockView(
            $this->decisionBlocks->withSelections($blockHtml, $selected, readOnly: false),
        );
    }
}
