<?php

declare(strict_types=1);

namespace App\Module\Project\Security;

use App\Module\Project\Entity\Project;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<'project.view'|'project.manage', Project>
 */
final class ProjectVoter extends Voter
{
    public const string VIEW = 'project.view';
    public const string MANAGE = 'project.manage';

    private const array SUPPORTED_ATTRIBUTES = [
        self::VIEW,
        self::MANAGE,
    ];

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::SUPPORTED_ATTRIBUTES, strict: true)
            && $subject instanceof Project;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return $subject->owner === $token->getUser();
    }
}
