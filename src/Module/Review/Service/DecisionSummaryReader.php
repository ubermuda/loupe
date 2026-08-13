<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Repository\DecisionSelectionRepository;
use App\Module\Review\ValueObject\Decision;
use App\Module\Review\ValueObject\DecisionSummary;

/**
 * Reads a version's decision blocks together with the answers on record.
 *
 * Answers are keyed to the document, so an earlier version shows the same ones
 * the latest does — a decision rendered blank reads as unanswered, which is a
 * different claim from "answered before this version".
 *
 * Every answer resolves through Decision::resolveIndex, exactly as GetReview
 * does. Resolving by a second rule is how the reviewer ends up seeing one option
 * ticked while the agent is told another.
 */
final readonly class DecisionSummaryReader
{
    public function __construct(
        private DecisionSelectionRepository $decisionSelections,
        private DecisionBlockService $decisionBlocks,
    ) {
    }

    public function __invoke(Document $document, DocumentVersion $version): DecisionSummary
    {
        $decisions = $this->decisionBlocks->extract($version->renderedHtml);
        $selections = $this->decisionSelections->findByDocumentIndexedByDecisionId($document);

        $selectedIndexByDecisionId = [];
        foreach ($decisions as $decision) {
            $selection = $selections[$decision->id] ?? null;
            $index = null === $selection
                ? null
                : $decision->resolveIndex($selection->optionLabel, $selection->optionIndex);
            if (null !== $index) {
                $selectedIndexByDecisionId[$decision->id] = $index;
            }
        }

        return new DecisionSummary($decisions, $selectedIndexByDecisionId);
    }
}
