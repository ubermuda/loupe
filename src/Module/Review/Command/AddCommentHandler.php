<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Service\AnchorService;
use App\Module\Review\ValueObject\Anchor;
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

        $version = $this->documentVersions->findLatest($command->document);
        $text = $version->plainText();

        $prefix = $command->prefix ?? '';
        $suffix = $command->suffix ?? '';
        $orphaned = false;

        if (null === $quote || '' === $quote) {
            $anchor = Anchor::unanchored();
        } else {
            $anchor = $this->anchorService->fromSelection($text, $quote, $prefix, $suffix);
            if (null === $anchor) {
                // Quote not found in the current text — revised in another tab,
                // say. Keep it and mark the comment orphaned rather than claim a
                // location that isn't real. offsetHint stays 0, so the flag and
                // not the offset is what a consumer must check.
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

        // No body, no quote and no replacement: all three are text a person
        // wrote. `suggested` says whether the comment carries a replacement.
        $this->auditor->record(
            'review.comment_added',
            AuditOutcome::Success,
            [
                'commentId' => (string) $comment->id,
                'documentId' => (string) $command->document->id,
                'versionId' => (string) $version->id,
                'orphaned' => $orphaned,
                'suggested' => null !== $command->replacement,
            ],
            new AuditSubject('comment', (string) $comment->id),
        );

        return $comment;
    }
}
