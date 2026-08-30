<?php

declare(strict_types=1);

namespace App\Module\Billing\Security;

use App\Module\Account\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Giving an account free access to a paid product is its own privilege. It is
 * admin-only today, and it stays separate from what gates the admin user page,
 * so either policy can change without the other.
 *
 * @extends Voter<'comp.manage', User>
 */
final class CompVoter extends Voter
{
    public const string MANAGE = 'comp.manage';

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MANAGE === $attribute && $subject instanceof User;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return in_array('ROLE_ADMIN', $token->getRoleNames(), true);
    }
}
