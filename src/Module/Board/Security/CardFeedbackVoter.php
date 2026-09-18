<?php

declare(strict_types=1);

namespace App\Module\Board\Security;

use App\Module\Board\Entity\CardSiteReviewComment;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<'card_feedback.reply'|'card_feedback.resolve'|'card_feedback.reopen', CardSiteReviewComment> */
final class CardFeedbackVoter extends Voter
{
    public const string REPLY = 'card_feedback.reply';
    public const string RESOLVE = 'card_feedback.resolve';
    public const string REOPEN = 'card_feedback.reopen';

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::REPLY, self::RESOLVE, self::REOPEN], true) && $subject instanceof CardSiteReviewComment;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return $subject->card->project->owner === $token->getUser()
            && $subject->comment->project->owner === $token->getUser()
            && $subject->card->project->id?->equals($subject->comment->project->id);
    }
}
