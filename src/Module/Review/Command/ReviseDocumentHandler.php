<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Service\MarkdownRenderer;
use App\Module\Review\Service\ReanchoringService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ReviseDocumentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
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
        $document = $command->document;

        return $this->em->wrapInTransaction(function () use ($document, $command): array {
            // Locks the documents row before anything reads $document->versions, so two
            // concurrent revisions of the same document serialize here instead of both
            // computing the same "next version number" from a collection loaded before
            // either lock was held (Document::addVersion() derives the number from the
            // collection's count — must stay the first thing that touches ->versions).
            $this->em->lock($document, LockMode::PESSIMISTIC_WRITE);

            // Capture the previous current version BEFORE adding the new one.
            $previousVersion = $document->currentVersion();

            // Add the new version (rendered from Markdown).
            $newVersion = $document->addVersion(
                $command->markdown,
                $this->renderer->render($command->markdown),
                $command->description,
            );

            // A revision may also correct the title; leaving it out means "keep
            // the current one" rather than "clear it".
            if (null !== $command->title) {
                $document->title = $command->title;
            }

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
        });
    }
}
