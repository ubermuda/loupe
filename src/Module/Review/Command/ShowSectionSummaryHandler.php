<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Service\HeadingExtractor;
use App\Module\Review\Service\SectionApprovalReader;

/**
 * The sections of the version the reviewer is looking at, with the approvals
 * that stand on them, for the Turbo stream that follows a press.
 *
 * The displayed version rather than the latest one, for the same reason the
 * decision summary reads the displayed one: a stale press is refused, so the
 * browser still shows the older prose and a summary built from the newer version
 * would list headings that are not on the page.
 */
final readonly class ShowSectionSummaryHandler
{
    public function __construct(
        private DocumentVersionRepository $documentVersions,
        private HeadingExtractor $headings,
        private SectionApprovalReader $sectionApprovals,
    ) {
    }

    public function __invoke(ShowSectionSummaryCommand $command): ShowSectionSummaryView
    {
        // The number rides in on an unvalidated submission, so an unknown one
        // means the latest.
        $displayed = null === $command->displayedVersionNumber
            ? null
            : $this->documentVersions->findByNumber($command->document, $command->displayedVersionNumber);

        $version = $displayed ?? $this->documentVersions->findLatest($command->document);
        $summary = ($this->sectionApprovals)(
            $command->document,
            $version,
            $this->headings->extract($version->renderedHtml),
            $command->reader,
        );

        return new ShowSectionSummaryView($summary->rows(), $summary->approvedCount());
    }
}
