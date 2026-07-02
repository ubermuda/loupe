<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Security;

use App\Module\SiteReview\Entity\Site;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<'site.view'|'site.manage', Site>
 */
final class SiteVoter extends Voter
{
    public const string VIEW = 'site.view';
    public const string MANAGE = 'site.manage';

    private const array SUPPORTED_ATTRIBUTES = [
        self::VIEW,
        self::MANAGE,
    ];

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::SUPPORTED_ATTRIBUTES, strict: true)
            && $subject instanceof Site;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return $subject->owner === $token->getUser();
    }
}
