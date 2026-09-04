<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Account\Entity\User;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Entity\SectionApproval;
use App\Module\Review\Repository\SectionApprovalRepository;
use App\Module\Review\ValueObject\DocumentHeading;
use App\Module\Review\ValueObject\SectionApprovalSummary;

/**
 * Reads a version's sections together with the approvals that stand on them:
 * one reader's own for the page, every reviewer's for a machine-facing caller.
 *
 * A stored approval counts only while its digest still matches the section at
 * that heading. A revision drops the ones that no longer match, so the check
 * here is a second gate: it also covers a version whose HTML was re-rendered in
 * place, which writes no new version and so runs no carry-forward.
 */
final readonly class SectionApprovalReader
{
    public function __construct(
        private SectionApprovalRepository $sectionApprovals,
        private SectionHasher $hasher,
    ) {
    }

    /**
     * @param list<DocumentHeading> $headings
     */
    public function __invoke(Document $document, DocumentVersion $version, array $headings, ?User $reader): SectionApprovalSummary
    {
        if ([] === $headings || null === $reader) {
            return new SectionApprovalSummary($headings, []);
        }

        $hashes = $this->hasher->hashes($version->renderedHtml, $headings);

        $approved = [];
        foreach ($this->sectionApprovals->findByDocumentAndApproverIndexedByHeadingId($document, $reader) as $headingId => $approval) {
            if ($this->stands($approval, $version, $hashes)) {
                $approved[$headingId] = true;
            }
        }

        return new SectionApprovalSummary($headings, $approved);
    }

    /**
     * How many reviewers' approvals still stand on each section of this version.
     *
     * Every approver counts, not only one reader, because a machine-facing
     * caller reads this to learn what the review has settled rather than what it
     * approved itself. The same digest and version rules apply as above.
     *
     * @param list<DocumentHeading> $headings
     *
     * @return array<string, int> heading id to standing approval count, one entry per heading
     */
    public function standingCounts(Document $document, DocumentVersion $version, array $headings): array
    {
        $counts = array_fill_keys(array_map(static fn (DocumentHeading $heading): string => $heading->id, $headings), 0);

        if ([] === $headings) {
            return $counts;
        }

        $hashes = $this->hasher->hashes($version->renderedHtml, $headings);

        foreach ($this->sectionApprovals->findByDocument($document) as $approval) {
            // An approval outlives the heading it names, so a row this version
            // no longer carries belongs to no section here.
            if (isset($counts[$approval->headingId]) && $this->stands($approval, $version, $hashes)) {
                ++$counts[$approval->headingId];
            }
        }

        return $counts;
    }

    /**
     * The version guard is what keeps an older version honest: an approval given
     * later can still match its text, and marking the section approved there
     * claims a reader approved it before they did.
     *
     * @param array<string, string> $hashes
     */
    private function stands(SectionApproval $approval, DocumentVersion $version, array $hashes): bool
    {
        return $approval->versionNumber <= $version->versionNumber
            && ($hashes[$approval->headingId] ?? null) === $approval->contentHash;
    }
}
