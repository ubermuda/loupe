<?php

declare(strict_types=1);

namespace App\Module\Account\Security;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

/** Signals that the social identity must be linked via password confirmation first. */
final class RequiresPasswordLinkException extends AuthenticationException
{
}
