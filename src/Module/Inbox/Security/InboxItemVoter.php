<?php

declare(strict_types=1);

namespace App\Module\Inbox\Security;

use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Service\InboxReviewLookup;
use App\Module\Review\Security\DocumentVoter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<'inbox_item.answer'|'inbox_item.review_document'|'inbox_item.reply', InboxItem>
 */
final class InboxItemVoter extends Voter
{
    /** Answering, marking done and declining: every write the owner makes on an item. */
    public const string ANSWER = 'inbox_item.answer';
    public const string REVIEW_DOCUMENT = 'inbox_item.review_document';
    public const string REPLY = 'inbox_item.reply';

    public function __construct(
        private readonly InboxReviewLookup $inboxReviews,
        private readonly AuthorizationCheckerInterface $authorization,
    ) {
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::ANSWER, self::REVIEW_DOCUMENT, self::REPLY], true) && $subject instanceof InboxItem;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if ($subject->project->owner !== $token->getUser()) {
            return false;
        }
        if (self::REVIEW_DOCUMENT !== $attribute) {
            return true;
        }
        $document = $this->inboxReviews->forItem($subject)?->document;

        return null === $document || $this->authorization->isGranted(DocumentVoter::CONTRIBUTE, $document);
    }
}
