<?php

declare(strict_types=1);

namespace App\Module\Account\Security;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Signals that a social login would have had to create an account on an
 * instance where sign-up is switched off, or where the install wizard has not
 * run yet. Distinct from WaitlistedException: a full instance can still take
 * names, a closed one has nothing to offer.
 */
final class RegistrationClosedException extends AuthenticationException
{
}
