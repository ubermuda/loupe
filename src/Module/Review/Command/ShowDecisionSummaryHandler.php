<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Service\DecisionSummaryReader;

/**
 * The decision blocks of the version the reviewer is looking at, together with
 * the answers on record, for the Turbo stream that follows an answer.
 *
 * The displayed version rather than the latest one. They are the same whenever
 * an answer succeeds, because a stale answer is refused — and it is exactly
 * that refusal that makes the difference matter: the browser still shows the
 * older prose, so a summary built from the newer version would count blocks
 * that are not on the page and link to element ids that do not exist on it.
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
        // The number rides in on an unvalidated submission, so an unknown one
        // means the latest — the same fallback as a request that names none.
        $displayed = null === $command->displayedVersionNumber
            ? null
            : $this->documentVersions->findByNumber($command->document, $command->displayedVersionNumber);

        $summary = ($this->decisionSummary)(
            $command->document,
            $displayed ?? $this->documentVersions->findLatest($command->document),
        );

        return new ShowDecisionSummaryView($summary->rows(), $summary->answeredCount());
    }
}
