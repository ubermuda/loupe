<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Module\Account\Entity\User;

/**
 * Result of resolving a social login: which user to log in, whether the
 * caller must first require a password link (the provider email collides with a
 * password-protected account) instead of logging the user in, and whether the
 * provider email was diverted to the waitlist instead of creating an account.
 *
 * Lives in Service\ rather than Command\ because its static factory methods
 * would trip gamache's CommandShapeRule (command.hasPublicMethods).
 */
final readonly class SocialLoginOutcome
{
    private function __construct(
        public ?User $user,
        public bool $requiresPasswordLink,
        public bool $waitlisted,
    ) {
    }

    public static function logIn(User $user): self
    {
        return new self($user, false, false);
    }

    public static function requiresPasswordLink(User $user): self
    {
        return new self($user, true, false);
    }

    /** No user is created — the provider email was added to the waitlist instead. */
    public static function waitlisted(): self
    {
        return new self(null, false, true);
    }
}
