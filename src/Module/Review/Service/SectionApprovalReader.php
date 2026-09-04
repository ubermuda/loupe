<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Account\Entity\User;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Repository\SectionApprovalRepository;
use App\Module\Review\ValueObject\DocumentHeading;
use App\Module\Review\ValueObject\SectionApprovalSummary;

/**
 * Reads a version's sections together with the reader's own approvals.
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
            // The version guard is what keeps an older version honest: an
            // approval given later can still match its text, and marking the
            // section approved there claims a reader approved it before they did.
            if ($approval->versionNumber <= $version->versionNumber
                && ($hashes[$headingId] ?? null) === $approval->contentHash
            ) {
                $approved[$headingId] = true;
            }
        }

        return new SectionApprovalSummary($headings, $approved);
    }
}
