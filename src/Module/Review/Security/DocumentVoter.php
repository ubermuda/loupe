<?php

declare(strict_types=1);

namespace App\Module\Review\Security;

use App\Module\Review\Entity\Document;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<'document.view'|'document.contribute'|'document.manage', Document>
 */
final class DocumentVoter extends Voter
{
    public const string VIEW = 'document.view';

    /** Taking part in the review — commenting, striking, suggesting, deciding, submitting a verdict. */
    public const string CONTRIBUTE = 'document.contribute';

    /** Owning the document itself — renaming it, archiving it, restoring it. */
    public const string MANAGE = 'document.manage';

    private const array SUPPORTED_ATTRIBUTES = [
        self::VIEW,
        self::CONTRIBUTE,
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

        // The three arms apply the same rule today and are kept apart on purpose:
        // widening reading to collaborators is a change to the VIEW arm alone,
        // and must not hand every collaborator the review writes — let alone
        // rename and archive — as a side effect of an edit that never mentioned
        // them.
        return match ($attribute) {
            self::VIEW => $subject->owner === $user,
            self::CONTRIBUTE => $subject->owner === $user,
            self::MANAGE => $subject->owner === $user,
        };
    }
}
