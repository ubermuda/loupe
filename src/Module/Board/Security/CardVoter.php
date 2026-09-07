<?php

declare(strict_types=1);

namespace App\Module\Board\Security;

use App\Module\Board\Entity\Card;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<'card.view'|'card.write', Card>
 */
final class CardVoter extends Voter
{
    public const string VIEW = 'card.view';

    public const string WRITE = 'card.write';

    private const array SUPPORTED_ATTRIBUTES = [
        self::VIEW,
        self::WRITE,
    ];

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::SUPPORTED_ATTRIBUTES, strict: true)
            && $subject instanceof Card;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        // Both arms apply the same rule and stay apart on purpose: opening the
        // board to a collaborator is a change to VIEW alone, and must not hand
        // that collaborator the writes as a side effect.
        return match ($attribute) {
            self::VIEW => $subject->project->owner === $user,
            self::WRITE => $subject->project->owner === $user,
        };
    }
}
