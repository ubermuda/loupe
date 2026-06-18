<?php

declare(strict_types=1);

namespace App\Module\Review\Security;

use App\Module\Review\Entity\Document;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<'document.view', Document>
 */
final class DocumentVoter extends Voter
{
    public const string VIEW = 'document.view';

    private const array SUPPORTED_ATTRIBUTES = [
        self::VIEW,
    ];

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::SUPPORTED_ATTRIBUTES, strict: true)
            && $subject instanceof Document;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $subject->owner === $user;
    }
}
