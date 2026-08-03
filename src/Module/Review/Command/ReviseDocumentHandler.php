<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Service\DocumentReferenceValidator;
use App\Module\Review\Service\DocumentSearchIndexer;
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
        private DocumentReferenceValidator $referenceValidator,
        private DocumentSearchIndexer $searchIndexer,
    ) {
    }

    /**
     * @return array{carried: int, orphaned: int}
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

        return $this->em->wrapInTransaction(function () use ($document, $command, $description, $title, $references): array {
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
                $description,
            );

            // A revision may also correct the title; leaving it out means "keep
            // the current one" rather than "clear it".
            if (null !== $title) {
                $document->title = $title;
            }

            // A list replaces the whole set, so leaving it out is the only way to
            // keep the current references — an empty list is how they are cleared.
            if (null !== $references) {
                $document->references->clear();
                foreach ($references as $reference) {
                    $document->references->add($reference);
                }
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

            // Inside the transaction: a revision that rolls back must not leave
            // the vector describing a version that no longer exists.
            $this->searchIndexer->index($document);

            return $summary;
        });
    }
}
