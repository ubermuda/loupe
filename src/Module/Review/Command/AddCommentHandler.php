<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Service\AnchorService;
use App\Module\Review\ValueObject\Anchor;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class AddCommentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private AnchorService $anchorService,
        private DocumentVersionRepository $documentVersions,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(AddCommentCommand $command): Comment
    {
        if ($command->document->owner !== $command->actor) {
            throw new DomainErrors(['actor' => 'comment.error.not_owner']);
        }

        $quote = $command->quote;

        // A strike or a rewording says what a specific passage should become, so it
        // is meaningless without one. A prose comment may still be untargeted.
        if (null !== $command->replacement && (null === $quote || '' === $quote)) {
            throw new DomainErrors(['quote' => 'review.document.suggestion.error.no_anchor']);
        }

        $comment = $this->em->wrapInTransaction(function () use ($command, $quote): Comment|DomainErrors {
            $this->em->lock($command->document, LockMode::PESSIMISTIC_WRITE);
            $version = $this->documentVersions->findLatest($command->document);
            if ($version->versionNumber !== $command->displayedVersionNumber) {
                return new DomainErrors(['versionNumber' => 'review.document.comment.error.stale_version']);
            }
            $text = $version->plainText();

            $prefix = $command->prefix ?? '';
            $suffix = $command->suffix ?? '';
            $orphaned = false;

            if (null === $quote || '' === $quote) {
                $anchor = Anchor::unanchored();
            } else {
                $anchor = $this->anchorService->fromSelection($text, $quote, $prefix, $suffix);
                if (null === $anchor) {
                    $anchor = new Anchor($quote, $prefix, $suffix, 0);
                    $orphaned = true;
                }
            }

            $comment = new Comment(
                version: $version,
                author: $command->actor,
                body: $command->body,
                anchor: $anchor,
                replacement: $command->replacement,
            );
            $comment->orphaned = $orphaned;

            $this->em->persist($comment);
            $this->em->flush();

            return $comment;
        });

        if ($comment instanceof DomainErrors) {
            throw $comment;
        }

        // No body, no quote and no replacement: all three are text a person
        // wrote. `suggested` says whether the comment carries a replacement.
        $this->auditor->record(
            'review.comment_added',
            AuditOutcome::Success,
            [
                'commentId' => (string) $comment->id,
                'documentId' => (string) $command->document->id,
                'versionId' => (string) $comment->version->id,
                'orphaned' => $comment->orphaned,
                'suggested' => null !== $command->replacement,
            ],
            new AuditSubject('comment', (string) $comment->id),
        );

        return $comment;
    }
}
