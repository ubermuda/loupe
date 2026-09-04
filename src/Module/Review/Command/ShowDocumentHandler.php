<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Service\DecisionBlockService;
use App\Module\Review\Service\DecisionSummaryReader;
use App\Module\Review\Service\HeadingExtractor;
use App\Module\Review\Service\LastSeenVersionResolver;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class ShowDocumentHandler
{
    public function __construct(
        private DocumentVersionRepository $documentVersions,
        private CommentRepository $comments,
        private HeadingExtractor $headings,
        private DecisionBlockService $decisionBlocks,
        private DecisionSummaryReader $decisionSummary,
        private LastSeenVersionResolver $lastSeenVersion,
    ) {
    }

    public function __invoke(ShowDocumentCommand $command): ShowDocumentView
    {
        $latest = $this->documentVersions->findLatest($command->document);
        $version = null === $command->versionNumber
            ? $latest
            : $this->version($command->document, $command->versionNumber);

        // Every write on this page targets the current version: the composer posts
        // a comment onto whatever is latest, and the verdict applies to the document
        // as it stands. An older version is therefore rendered as a read-only record
        // of what was discussed then.
        $isLatest = $version->versionNumber === $latest->versionNumber;
        $comments = $this->comments->findByVersion($version);
        $decisions = ($this->decisionSummary)($command->document, $version);

        return new ShowDocumentView(
            document: $command->document,
            version: $version,
            readOnly: !$isLatest,
            comments: $comments,
            versions: $this->documentVersions->findAllMetaByDocument($command->document),
            headings: $this->headings->extract($version->renderedHtml),
            orphanedCount: count(array_filter($comments, static fn (Comment $c) => $c->orphaned)),
            decisions: $decisions,
            decisionMarkedHtml: $this->decisionBlocks->withSelections(
                $version->renderedHtml,
                $decisions->selectedIndexesByDecisionId,
                readOnly: !$isLatest,
            ),
            lastSeenVersionNumber: $this->lastSeenVersion->versionNumberFor($command->document, $command->reader),
        );
    }

    private function version(Document $document, int $versionNumber): DocumentVersion
    {
        return $this->documentVersions->findByNumber($document, $versionNumber)
            ?? throw new NotFoundHttpException(\sprintf('Document has no version %d.', $versionNumber));
    }
}
