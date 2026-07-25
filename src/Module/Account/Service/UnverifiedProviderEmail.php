<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Thrown when the provider did not assert a verified email. Every Loupe account
 * needs a verified email (User.email is non-nullable and is the login identity),
 * so the social login is rejected rather than creating an email-less account.
 * Extends AuthenticationException so the authenticator surfaces it as a login
 * failure rather than a 500.
 */
final class UnverifiedProviderEmail extends AuthenticationException
{
    #[\Override]
    public function getMessageKey(): string
    {
        return 'account.social.error.unverified_email';
    }
}
