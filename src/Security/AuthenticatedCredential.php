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
 *
 * `allProjects` covers every project the owner owns, read per request. It is
 * exclusive with `projectId`: one names a set and the other names a member, so
 * a credential carrying both would answer the same question twice.
 *
 * `roles` holds one role per base scope the credential carries. One credential
 * reaches several firewalls, because the CLI needs the bridge endpoints and the
 * MCP endpoint from one login.
 */
final readonly class AuthenticatedCredential
{
    public const string ATTRIBUTE = 'authenticatedCredential';

    /** @param non-empty-list<string> $roles */
    public function __construct(
        public string $id,
        public array $roles,
        public ?Uuid $projectId = null,
        public bool $allProjects = false,
    ) {
        if ($allProjects && null !== $projectId) {
            throw new \LogicException('a credential covers one project or every project of its owner, never both.');
        }
    }

    public function hasRole(string $role): bool
    {
        return \in_array($role, $this->roles, true);
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
