<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Service\MarkdownDiffer;
use App\Module\Review\ValueObject\DiffRefusal;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class DiffDocumentVersionsHandler
{
    public function __construct(
        private DocumentVersionRepository $documentVersions,
        private CommentRepository $comments,
        private MarkdownDiffer $markdownDiffer,
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
        $diffRefusal = null;
        if ($result instanceof DiffRefusal) {
            $diffRefusal = $result;
            $this->logger->info('review.document.diff_refused', [
                'documentId' => (string) $command->document->id,
                'from' => $command->fromVersionNumber,
                'to' => $command->toVersionNumber,
                'reason' => $result->value,
            ]);
        } else {
            $diff = $result;
        }

        // A diff never accepts a comment or a verdict: the pane holds diff markup
        // rather than the document, so anchoring has no text basis to resolve
        // against. That is the same `readOnly` the review page uses for an earlier
        // version, and it is why no comment form is built here.
        $comments = $this->comments->findByVersion($version);

        return new DiffDocumentVersionsView(
            version: $version,
            diff: $diff,
            diffRefusal: $diffRefusal,
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
