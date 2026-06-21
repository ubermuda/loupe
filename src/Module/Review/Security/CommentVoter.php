<?php

declare(strict_types=1);

namespace App\Module\Review\Security;

use App\Module\Review\Entity\Comment;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<'comment.delete'|'comment.resolve'|'comment.reply', Comment>
 */
final class CommentVoter extends Voter
{
    public const string DELETE = 'comment.delete';
    public const string RESOLVE = 'comment.resolve';
    public const string REPLY = 'comment.reply';

    private const array SUPPORTED_ATTRIBUTES = [
        self::DELETE,
        self::RESOLVE,
        self::REPLY,
    ];

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::SUPPORTED_ATTRIBUTES, strict: true)
            && $subject instanceof Comment;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return $subject->version->document->owner === $token->getUser();
    }
}
