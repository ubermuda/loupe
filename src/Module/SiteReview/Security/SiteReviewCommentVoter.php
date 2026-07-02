<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Security;

use App\Module\SiteReview\Entity\SiteReviewComment;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<'site_review_comment.resolve'|'site_review_comment.reopen', SiteReviewComment>
 */
final class SiteReviewCommentVoter extends Voter
{
    public const string RESOLVE = 'site_review_comment.resolve';
    public const string REOPEN = 'site_review_comment.reopen';

    private const array SUPPORTED_ATTRIBUTES = [
        self::RESOLVE,
        self::REOPEN,
    ];

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::SUPPORTED_ATTRIBUTES, strict: true)
            && $subject instanceof SiteReviewComment;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return $subject->review->site->owner === $token->getUser();
    }
}
