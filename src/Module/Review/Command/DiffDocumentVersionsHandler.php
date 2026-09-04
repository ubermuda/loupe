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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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
        private RenderedDiffBuilder $renderedDiffs,
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
        $diffRefusal = null;
        $changeCount = null;
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
        }

        // A comment always lands on the latest version, so anchoring one against
        // the diff's newer side only holds while that side IS the latest version.
        // On any older pair the pane stays read-only, and the plain text the
        // rendered diff would be measured against is not read at all.
        $isCurrent = $this->documentVersions->findLatest($command->document)->versionNumber === $version->versionNumber;

        // Only the showing view is built. Rendering the other one costs a whole
        // Markdown pass, and its count would then be on the page describing jump
        // targets that are not.
        if (null !== $diff && $diff->hasChanges()) {
            if (DiffView::Source === $command->view) {
                $changeCount = $diff->changeCount();
            } else {
                $renderedDiff = $this->renderedDiffs->build(
                    $this->markdownRenderer->renderDiff($diff),
                    $isCurrent ? $version->plainText() : null,
                );
                $changeCount = $renderedDiff->changeCount;
            }
        }

        // A verdict still belongs to the document rather than to a comparison, so
        // the page stays `readOnly` even where commenting is offered.
        $comments = $this->comments->findByVersion($version);

        return new DiffDocumentVersionsView(
            version: $version,
            view: $command->view,
            diff: $diff,
            renderedDiff: $renderedDiff,
            diffRefusal: $diffRefusal,
            changeCount: $changeCount,
            commentingEnabled: $isCurrent && null !== $renderedDiff,
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
