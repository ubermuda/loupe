<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Service\MarkdownDiffer;
use App\Module\Review\Service\MarkdownRenderer;
use App\Module\Review\Service\RenderedDiffBuilder;
use App\Module\Review\ValueObject\DiffRefusal;
use App\Module\Review\ValueObject\DiffView;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class DiffDocumentVersionsHandler
{
    public function __construct(
        private DocumentVersionRepository $documentVersions,
        private CommentRepository $comments,
        private MarkdownDiffer $markdownDiffer,
        private MarkdownRenderer $markdownRenderer,
        private RenderedDiffBuilder $renderedDiffs,
        private LoggerInterface $logger,
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
        $diffRefusal = null;
        $changeCount = null;
        if ($result instanceof DiffRefusal) {
            $diffRefusal = $result;
            $this->logger->info('review.document_diff_refused', [
                'documentId' => (string) $command->document->id,
                'from' => $command->fromVersionNumber,
                'to' => $command->toVersionNumber,
                'reason' => $result->value,
            ]);
        } else {
            $diff = $result;
        }

        // Only the showing view is built. Rendering the other one costs a whole
        // Markdown pass, and its count would then be on the page describing jump
        // targets that are not.
        if (null !== $diff && $diff->hasChanges()) {
            if (DiffView::Source === $command->view) {
                $changeCount = $diff->changeCount();
            } else {
                $renderedDiff = $this->renderedDiffs->build($this->markdownRenderer->renderDiff($diff));
                $changeCount = $renderedDiff->changeCount;
            }
        }

        // A diff never accepts a comment or a verdict: its pane holds the text of
        // two versions at once, so an anchor has no single version to resolve
        // against. That is the same `readOnly` the review page uses for an earlier
        // version, and it is why no comment form is built here.
        $comments = $this->comments->findByVersion($version);

        return new DiffDocumentVersionsView(
            version: $version,
            view: $command->view,
            diff: $diff,
            renderedDiff: $renderedDiff,
            diffRefusal: $diffRefusal,
            changeCount: $changeCount,
            comments: $comments,
            versions: $this->documentVersions->findAllMetaByDocument($command->document),
            orphanedCount: count(array_filter($comments, static fn (Comment $c) => $c->orphaned)),
        );
    }

    private function version(Document $document, int $versionNumber): DocumentVersion
    {
        return $this->documentVersions->findByNumber($document, $versionNumber)
            ?? throw new NotFoundHttpException(\sprintf('Document has no version %d.', $versionNumber));
    }
}
