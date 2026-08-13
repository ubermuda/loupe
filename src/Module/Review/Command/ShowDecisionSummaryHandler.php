<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Service\DecisionSummaryReader;

/**
 * The decision blocks of the document's latest version and the answers on
 * record, for the Turbo stream that follows an answer.
 *
 * Always the latest version, never the one the reviewer submitted from: an
 * answer only ever applies to what is current, so a summary built from an older
 * version would report on blocks that no longer exist.
 */
final readonly class ShowDecisionSummaryHandler
{
    public function __construct(
        private DocumentVersionRepository $documentVersions,
        private DecisionSummaryReader $decisionSummary,
    ) {
    }

    public function __invoke(ShowDecisionSummaryCommand $command): ShowDecisionSummaryView
    {
        $summary = ($this->decisionSummary)(
            $command->document,
            $this->documentVersions->findLatest($command->document),
        );

        return new ShowDecisionSummaryView($summary->rows(), $summary->answeredCount());
    }
}
