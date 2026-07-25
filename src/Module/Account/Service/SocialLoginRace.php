<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

/**
 * A concurrent OAuth callback won a uniqueness race (users.email or the
 * identity's (provider, provider_user_id)). The EntityManager is closed at this
 * point, so nothing can be retried within the same request: the caller turns
 * this into a generic login failure. The user's next attempt resolves through
 * the winner's rows and succeeds.
 */
final class SocialLoginRace extends \RuntimeException
{
}
