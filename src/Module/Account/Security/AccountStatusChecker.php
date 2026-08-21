<?php

declare(strict_types=1);

namespace App\Module\Account\Security;

use App\Module\Account\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The account-state gate for the stateless firewalls. Named for the slot rather
 * than the check: Symfony allows one user_checker per firewall with no
 * chaining, so a second condition lands here too.
 */
final readonly class AccountStatusChecker implements UserCheckerInterface
{
    /**
     * Deliberately empty. Pre-auth runs before the credential is accepted, so a
     * rejection here would answer "is this account suspended?" to anyone who
     * can name the account, and hand them the reason with it.
     */
    #[\Override]
    public function checkPreAuth(UserInterface $user): void
    {
    }

    #[\Override]
    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (!$user instanceof User || !$user->isSuspended()) {
            return;
        }

        throw new CustomUserMessageAccountStatusException($user->suspendedReason ?? 'This account has been suspended.');
    }
}
