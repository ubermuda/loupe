<?php

declare(strict_types=1);

namespace App\Module\Review\Security;

use App\Module\Review\Entity\Document;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<'document.view'|'document.manage', Document>
 */
final class DocumentVoter extends Voter
{
    public const string VIEW = 'document.view';

    /** Every write on a document — renaming it, archiving it, restoring it. */
    public const string MANAGE = 'document.manage';

    private const array SUPPORTED_ATTRIBUTES = [
        self::VIEW,
        self::MANAGE,
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
