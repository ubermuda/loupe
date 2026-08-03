<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Service\AnchorService;
use App\Module\Review\ValueObject\Anchor;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AddCommentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private AnchorService $anchorService,
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

        $version = $command->document->currentVersion();
        $text = $version->plainText();

        $prefix = $command->prefix ?? '';
        $suffix = $command->suffix ?? '';
        $orphaned = false;

        if (null === $quote || '' === $quote) {
            $anchor = Anchor::unanchored();
        } else {
            $anchor = $this->anchorService->fromSelection($text, $quote, $prefix, $suffix);
            if (null === $anchor) {
                // The quote wasn't found in the current text at all (e.g. the document
                // was revised in another tab between selection and submit). Keep the
                // captured quote/context as-is but mark the comment orphaned — the same
                // representation ReanchoringService already uses for a comment whose
                // anchor no longer resolves — rather than claim a location that isn't
                // real. offsetHint stays 0: there is no meaningful position to record,
                // so the orphaned flag (not the offset) is the signal a consumer must
                // check; this can still sort the thread first in the sidebar, same as
                // any other 0-offset entry.
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
    }
}
