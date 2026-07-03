<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Query\DocumentNotFound;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Service\MarkdownRenderer;
use App\Module\Review\Service\ReanchoringService;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ReviseDocumentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private DocumentRepository $documents,
        private MarkdownRenderer $renderer,
        private ReanchoringService $reanchoringService,
        private CommentRepository $comments,
    ) {
    }

    /**
     * @return array{carried: int, orphaned: int}
     */
    public function __invoke(ReviseDocumentCommand $command): array
    {
        $document = $this->documents->findOneByIdAndProject($command->documentId, $command->project);
        if (null === $document) {
            throw DocumentNotFound::forId($command->documentId);
        }

        // Capture the previous current version BEFORE adding the new one.
        $previousVersion = $document->currentVersion();

        // Add the new version (rendered from Markdown).
        $newVersion = $document->addVersion(
            $command->markdown,
            $this->renderer->render($command->markdown),
        );

        // Collect all open (unresolved) comments from the previous version. Orphaned-but-
        // unresolved comments are intentionally included so they are re-evaluated against the
        // new text: if the quoted passage reappears in this revision, the copy re-anchors and
        // is no longer orphaned; otherwise it carries forward still orphaned (one copy per
        // version, not an accumulating duplicate).
        $openComments = $this->comments->findOpenByVersion($previousVersion);

        // Re-anchor them onto the new version; copies are attached to $newVersion->comments.
        $summary = $this->reanchoringService->reanchor($openComments, $newVersion);

        // Transition document status back to in-review.
        $document->status = DocumentStatus::InReview;

        // Flush: Document → versions cascade persists new version; version → comments cascade persists copies.
        $this->em->flush();

        return $summary;
    }
}
