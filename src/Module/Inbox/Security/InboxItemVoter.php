<?php

declare(strict_types=1);

namespace App\Module\Inbox\Security;

use App\Module\Inbox\Entity\InboxItem;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<'inbox_item.answer', InboxItem>
 */
final class InboxItemVoter extends Voter
{
    /** Answering, marking done and declining: every write the owner makes on an item. */
    public const string ANSWER = 'inbox_item.answer';

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ANSWER === $attribute && $subject instanceof InboxItem;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return $subject->project->owner === $token->getUser();
    }
}
