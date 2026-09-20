<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The credential that authenticated a machine request, whichever authenticator
 * accepted it. The id stays the same for the life of the credential, so a rate
 * limit keys on it. A credential that names no project leaves the binding to
 * the project that holds the credential.
 */
final readonly class AuthenticatedCredential
{
    public const string ATTRIBUTE = 'authenticatedCredential';

    public function __construct(
        public string $id,
        public string $scopeRole,
        public ?Uuid $projectId = null,
    ) {
    }

    public static function of(?TokenInterface $securityToken): ?self
    {
        if (null === $securityToken || !$securityToken->hasAttribute(self::ATTRIBUTE)) {
            return null;
        }

        $credential = $securityToken->getAttribute(self::ATTRIBUTE);

        return $credential instanceof self ? $credential : null;
    }
}
