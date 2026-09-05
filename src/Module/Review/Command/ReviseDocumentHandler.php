<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Entity\Series;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Repository\SectionApprovalRepository;
use App\Module\Review\Service\DocumentReferenceValidator;
use App\Module\Review\Service\DocumentSearchIndexer;
use App\Module\Review\Service\DocumentSeriesApplier;
use App\Module\Review\Service\HeadingExtractor;
use App\Module\Review\Service\MarkdownRenderer;
use App\Module\Review\Service\ReanchoringService;
use App\Module\Review\Service\SectionHasher;
use App\Module\Review\Service\SeriesConflictErrors;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * @phpstan-type RevisionSummary array{carried: int, orphaned: int, sectionsCarried: int, sectionsDropped: int}
 */
final readonly class ReviseDocumentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private MarkdownRenderer $renderer,
        private ReanchoringService $reanchoringService,
        private CommentRepository $comments,
        private DocumentVersionRepository $documentVersions,
        private SectionApprovalRepository $sectionApprovals,
        private HeadingExtractor $headings,
        private SectionHasher $sectionHasher,
        private DocumentReferenceValidator $referenceValidator,
        private DocumentSeriesApplier $seriesApplier,
        private DocumentSearchIndexer $searchIndexer,
        private SeriesConflictErrors $conflicts,
        private Auditor $auditor,
    ) {
    }

    /**
     * @return RevisionSummary
     */
    public function __invoke(ReviseDocumentCommand $command): array
    {
        $document = $command->document;

        // Checked before the transaction opens: an over-long title would
        // otherwise reach Postgres inside wrapInTransaction and roll back the
        // whole revision — new version, re-anchored comments and all — as a 500
        // rather than a field error.
        $description = trim($command->description);
        if ('' === $description) {
            throw new DomainErrors(['description' => 'review.revise.error.description_blank']);
        }

        $title = null === $command->title ? null : trim($command->title);
        if (null !== $title) {
            if ('' === $title) {
                throw new DomainErrors(['title' => 'review.rename.error.blank']);
            }

            if (mb_strlen($title) > Document::MAX_TITLE_LENGTH) {
                throw new DomainErrors(['title' => 'review.rename.error.too_long']);
            }
        }

        // Validated before the transaction opens, so a set holding one bad id
        // never reaches the clear-and-re-add below: the whole revision is
        // rejected rather than landing with the good references only.
        $references = null === $command->references
            ? null
            : $this->referenceValidator->validated($document->project, $document, $command->references);

        // Both null means "leave the placement alone"; a blank name is how a
        // revision takes a document out of its series. Validated out here for
        // the same reason as the title: a rejected placement must not roll a
        // whole revision back as a 500.
        $placesInSeries = null !== $command->seriesName || null !== $command->seriesOrdinal;
        if ($placesInSeries) {
            Series::normalizePlacement($command->seriesName, $command->seriesOrdinal);
        }

        $newVersionNumber = 0;

        $summary = $this->em->wrapInTransaction(function () use ($document, $command, $description, $title, $references, $placesInSeries, &$newVersionNumber): array {
            // Locks the documents row before the number below is read, so two
            // concurrent revisions serialize here rather than both deriving the
            // same next version number.
            $this->em->lock($document, LockMode::PESSIMISTIC_WRITE);

            // Both reads go through the repository, and the version is built and
            // persisted directly rather than through Document::addVersion(): that
            // helper calls count() and add() on ->versions, and the association is
            // not EXTRA_LAZY, so either one loads every version of the document.
            $previousVersion = $this->documentVersions->findLatest($document);

            $newVersion = new DocumentVersion(
                $document,
                $this->documentVersions->nextVersionNumber($document),
                $command->markdown,
                $this->renderer->render($command->markdown),
                $description,
            );
            $this->em->persist($newVersion);
            // Kept in step in memory as well: currentVersion() reads the
            // collection, so anything reading the document later in this
            // request would otherwise still see the previous version.
            $document->versions->add($newVersion);

            // A revision may also correct the title; leaving it out means "keep
            // the current one" rather than "clear it".
            if (null !== $title) {
                $document->title = $title;
            }

            // A list replaces the whole set, so leaving it out is the only way to
            // keep the current references — an empty list is how they are cleared.
            if (null !== $references) {
                $document->clearReferences();
                foreach ($references as $reference) {
                    $document->addReference($reference);
                }
            }

            if ($placesInSeries) {
                $this->seriesApplier->apply($document, $command->seriesName, $command->seriesOrdinal);
            }

            // Collect all open (unresolved) comments from the previous version. Orphaned-but-
            // unresolved comments are intentionally included so they are re-evaluated against the
            // new text: if the quoted passage reappears in this revision, the copy re-anchors and
            // is no longer orphaned; otherwise it carries forward still orphaned (one copy per
            // version, not an accumulating duplicate).
            $openComments = $this->comments->findOpenByVersion($previousVersion);

            // Re-anchor them onto the new version; copies are attached to $newVersion->comments.
            $summary = $this->reanchoringService->reanchor($openComments, $newVersion);

            $sections = $this->carryForwardApprovals($document, $newVersion);

            // Transition document status back to in-review.
            $document->status = DocumentStatus::InReview;

            // Flush: the new version is persisted explicitly above; version → comments cascade persists the copies.
            try {
                $this->em->flush();
            } catch (UniqueConstraintViolationException $e) {
                throw $this->conflicts->forViolation($e) ?? $e;
            }

            // Inside the transaction: a revision that rolls back must not leave
            // the vector describing a version that no longer exists.
            $this->searchIndexer->index($document);

            $newVersionNumber = $newVersion->versionNumber;

            return [
                'carried' => $summary['carried'],
                'orphaned' => $summary['orphaned'],
                'sectionsCarried' => $sections['carried'],
                'sectionsDropped' => $sections['dropped'],
            ];
        });

        // After the commit, never inside it: the sink drains at kernel.terminate,
        // so a record written in the closure outlives a rollback.
        $this->auditor->record(
            'review.document_revised',
            AuditOutcome::Success,
            [
                'documentId' => (string) $document->id,
                'projectId' => (string) $document->project->id,
                'versionNumber' => $newVersionNumber,
                'titleChanged' => null !== $title,
                'referencesReplaced' => null !== $references,
                'seriesChanged' => $placesInSeries,
                'commentsCarried' => $summary['carried'],
                'commentsOrphaned' => $summary['orphaned'],
                'sectionsCarried' => $summary['sectionsCarried'],
                'sectionsDropped' => $summary['sectionsDropped'],
            ],
            new AuditSubject('document', (string) $document->id),
        );

        return $summary;
    }

    /**
     * Keeps an approval whose section reads exactly as it did, and drops the rest.
     *
     * The heading id and the digest must BOTH still match. The id alone would
     * keep an approval of text this revision replaced, which is the claim the
     * digest exists to refuse. A dropped row is deleted rather than flagged: the
     * reviewer approves the new text again, or leaves it open.
     *
     * @return array{carried: int, dropped: int}
     */
    private function carryForwardApprovals(Document $document, DocumentVersion $newVersion): array
    {
        $hashes = $this->sectionHasher->hashes(
            $newVersion->renderedHtml,
            $this->headings->extract($newVersion->renderedHtml),
        );

        $carried = 0;
        $dropped = 0;
        foreach ($this->sectionApprovals->findByDocument($document) as $approval) {
            if (($hashes[$approval->headingId] ?? null) === $approval->contentHash) {
                ++$carried;

                continue;
            }

            $this->em->remove($approval);
            ++$dropped;
        }

        return ['carried' => $carried, 'dropped' => $dropped];
    }
}
