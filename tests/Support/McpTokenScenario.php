<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Account\Entity\User;
use App\Module\OAuth\Scope\ApiScope;
use App\Module\Project\Entity\Project;
use App\Security\AuthenticatedCredential;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * KernelTestCase helper: simulate a request authenticated by an MCP credential,
 * the way OAuthAccessTokenAuthenticator would. The credential travels as a
 * security-token attribute that AuthenticatedProjectResolver reads back.
 */
trait McpTokenScenario
{
    /** Simulates a request by a credential bound to $project. */
    private function actAsMcpTokenBoundTo(Project $project): void
    {
        $this->setSecurityTokenForCredential($project->owner, $project->id);
    }

    /** Simulates a request by a credential that names no project at all. */
    private function actAsUnboundMcpToken(User $user): void
    {
        $this->setSecurityTokenForCredential($user, null);
    }

    private function setSecurityTokenForCredential(User $user, ?\Symfony\Component\Uid\Uuid $projectId): void
    {
        $securityToken = new PostAuthenticationToken($user, 'mcp', [...$user->getRoles(), ApiScope::Mcp->role()]);
        $securityToken->setAttribute(AuthenticatedCredential::ATTRIBUTE, new AuthenticatedCredential(
            'oauth:test:'.$user->id.':'.($projectId?->toRfc4122() ?? '-'),
            [ApiScope::Mcp->role()],
            $projectId,
        ));
        $tokenStorage = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokenStorage);
        $tokenStorage->setToken($securityToken);
    }
}
