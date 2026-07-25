<?php

declare(strict_types=1);

namespace App\Module\Account\Security;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

/** Signals that the social login was diverted to the waitlist instead of creating an account. */
final class WaitlistedException extends AuthenticationException
{
}
