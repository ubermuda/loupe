<?php

declare(strict_types=1);

namespace App\Module\Inbox\Security;

use App\Module\Inbox\Entity\InboxItem;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<'inbox_item.view'|'inbox_item.answer', InboxItem>
 */
final class InboxItemVoter extends Voter
{
    public const string VIEW = 'inbox_item.view';

    /** Answering, marking done and declining: every write the owner makes on an item. */
    public const string ANSWER = 'inbox_item.answer';

    private const array SUPPORTED_ATTRIBUTES = [
        self::VIEW,
        self::ANSWER,
    ];

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::SUPPORTED_ATTRIBUTES, strict: true)
            && $subject instanceof InboxItem;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        // Both arms apply the same rule and stay apart on purpose: letting another
        // person read an item must not also let them answer it.
        return match ($attribute) {
            self::VIEW => $subject->project->owner === $user,
            self::ANSWER => $subject->project->owner === $user,
        };
    }
}
