<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Repository\ReviewRepository;
use App\Module\Review\Service\DecisionBlockService;
use App\Module\Review\Service\DecisionSummaryReader;
use App\Module\Review\Service\HeadingExtractor;
use App\Module\Review\Service\MarkdownDiffer;
use App\Module\Review\Service\MarkdownRenderer;
use App\Module\Review\Service\RenderedDiffBuilder;
use App\Module\Review\Service\SideBySideDiffBuilder;
use App\Module\Review\Service\SourceHeadingIndexBuilder;
use App\Module\Review\ValueObject\CommentSignals;
use App\Module\Review\ValueObject\DiffRefusal;
use App\Module\Review\ValueObject\DiffView;
use App\Module\Review\ValueObject\DocumentHeading;
use App\Module\Review\ValueObject\SideBySideDiff;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class DiffDocumentVersionsHandler
{
    public function __construct(
        private DocumentVersionRepository $documentVersions,
        private CommentRepository $comments,
        private MarkdownDiffer $markdownDiffer,
        private MarkdownRenderer $markdownRenderer,
        private DecisionBlockService $decisionBlocks,
        private TranslatorInterface $translator,
        private RenderedDiffBuilder $renderedDiffs,
        private SideBySideDiffBuilder $sideBySideDiffs,
        private SourceHeadingIndexBuilder $sourceHeadingIndexes,
        private HeadingExtractor $headings,
        private DecisionSummaryReader $decisionSummary,
        private ReviewRepository $reviews,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(DiffDocumentVersionsCommand $command): DiffDocumentVersionsView
    {
        $version = $this->version($command->document, $command->toVersionNumber);
        $result = $this->markdownDiffer->diff(
            $this->version($command->document, $command->fromVersionNumber)->markdownSource,
            $version->markdownSource,
        );

        $diff = null;
        $renderedDiff = null;
        $sideBySide = null;
        $diffRefusal = null;
        $changeCount = null;
        $headings = [];
        $changesByHeading = [];
        $sourceHeadings = null;
        if ($result instanceof DiffRefusal) {
            $diffRefusal = $result;
            $this->auditor->record(
                'review.document_diff_refused',
                AuditOutcome::Refused,
                [
                    'documentId' => (string) $command->document->id,
                    'from' => $command->fromVersionNumber,
                    'to' => $command->toVersionNumber,
                    'reason' => $result->value,
                ],
                new AuditSubject('document', (string) $command->document->id),
            );
        } else {
            $diff = $result;
            $changeCount = 0;
        }

        // A comment always lands on the latest version, so anchoring one against
        // the diff's newer side only holds while that side IS the latest version.
        // On any older pair the pane stays read-only, and the plain text the
        // rendered diff would be measured against is not read at all.
        $isCurrent = $this->documentVersions->findLatest($command->document)->versionNumber === $version->versionNumber;

        // Build only the selected view so its count describes the visible jump targets.
        if (null !== $diff && $diff->hasChanges()) {
            if (DiffView::Source === $command->view) {
                $changeCount = $diff->changeCount();
                $sourceHeadings = $this->sourceHeadingIndexes->build($diff);
                $headings = $sourceHeadings->headings;
            } else {
                $rendered = $this->renderedDiffs->build(
                    $this->decisionBlocks->withBadgeLabels(
                        $this->markdownRenderer->renderDiff($diff),
                        DecisionBlockService::badgeLabels($this->translator),
                    ),
                    $isCurrent ? $version->plainText() : null,
                );
                $changeCount = $rendered->changeCount;

                if (DiffView::SideBySide === $command->view) {
                    $sideBySide = $this->sideBySideDiffs->build($rendered->html);
                    $headings = $this->columnHeadings($sideBySide);
                    // A heading edited in place shows an old and a new row under one
                    // document id. The change counts once, on the first row.
                    $counted = [];
                    foreach ($headings as $heading) {
                        $documentId = $this->documentId($heading->id);
                        if (isset($counted[$documentId])) {
                            continue;
                        }
                        $counted[$documentId] = true;
                        $changesByHeading[$heading->id] = $rendered->changesByHeadingId[$documentId] ?? 0;
                    }
                    $changesByHeading = array_filter($changesByHeading);
                } else {
                    $renderedDiff = $rendered;
                    $headings = $this->headings->extract($rendered->html);
                    $changesByHeading = $rendered->changesByHeadingId;
                }
            }
        }

        $comments = $this->comments->findByVersion($version);
        $latestReview = $this->reviews->findNewestByVersion($version);

        return new DiffDocumentVersionsView(
            version: $version,
            view: $command->view,
            diff: $diff,
            renderedDiff: $renderedDiff,
            sideBySide: $sideBySide,
            diffRefusal: $diffRefusal,
            changeCount: $changeCount,
            headings: $headings,
            changesByHeading: $changesByHeading,
            sourceHeadings: $sourceHeadings,
            commentingEnabled: $isCurrent && (null !== $renderedDiff || null !== $sideBySide),
            comments: $comments,
            versions: $this->documentVersions->findAllMetaByDocument($command->document),
            signals: $this->comments->signalsByVersions([(string) $version->id])[(string) $version->id] ?? new CommentSignals(),
            isCurrent: $isCurrent,
            decisions: ($this->decisionSummary)($command->document, $version),
            review: Verdict::Withdrawn === $latestReview?->verdict ? null : $latestReview,
            latestReviewId: $latestReview?->id?->toRfc4122(),
        );
    }

    /**
     * Every heading the columns hold, in document order, older cell first.
     *
     * The builder prefixes every id on the older side, so reading the merged
     * render named ids the columns do not hold and those rows scrolled nowhere.
     * Both cells are read, because a row can show two headings and a reader may
     * want either. A heading the revision left alone stands in both cells and is
     * listed once, under the id the newer one carries.
     *
     * @return list<DocumentHeading>
     */
    private function columnHeadings(SideBySideDiff $sideBySide): array
    {
        $headings = [];
        foreach ($sideBySide->rows as $row) {
            $new = $this->headings->extract($row->newHtml ?? '');
            $unchanged = array_map(
                static fn (DocumentHeading $heading): string => $heading->id."\0".$heading->text,
                $new,
            );

            foreach ($this->headings->extract($row->oldHtml ?? '') as $heading) {
                // Id and text together. A heading edited in place keeps its id,
                // because the id is a slug and `Hello` and `Hello!` slug alike,
                // so the id alone would call two different labels one heading.
                $key = $this->documentId($heading->id)."\0".$heading->text;
                if (!\in_array($key, $unchanged, true)) {
                    $headings[] = $heading;
                }
            }

            $headings = [...$headings, ...$new];
        }

        return $headings;
    }

    /** An older cell's id as the document minted it, before the column pass. */
    private function documentId(string $id): string
    {
        return str_starts_with($id, SideBySideDiffBuilder::OLD_SIDE_PREFIX)
            ? substr($id, \strlen(SideBySideDiffBuilder::OLD_SIDE_PREFIX))
            : $id;
    }

    private function version(Document $document, int $versionNumber): DocumentVersion
    {
        return $this->documentVersions->findByNumber($document, $versionNumber)
            ?? throw new NotFoundHttpException(\sprintf('Document has no version %d.', $versionNumber));
    }
}
