<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Module\Account\Entity\User;

/**
 * Result of resolving a social login: which user to log in, and whether the
 * caller must first require a password link (the provider email collides with a
 * password-protected account) instead of logging the user in.
 *
 * Lives in Service\ rather than Command\ because its static factory methods
 * would trip gamache's CommandShapeRule (command.hasPublicMethods).
 */
final readonly class SocialLoginOutcome
{
    private function __construct(
        public User $user,
        public bool $requiresPasswordLink,
    ) {
    }

    public static function logIn(User $user): self
    {
        return new self($user, false);
    }

    public static function requiresPasswordLink(User $user): self
    {
        return new self($user, true);
    }
}
