<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Account\Security\ApiTokenAuthenticator;
use App\Module\Project\Entity\Project;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * KernelTestCase helper: simulate a request authenticated by an MCP-scope
 * ApiToken, the way ApiTokenAuthenticator would (the ApiToken id travels as a
 * security-token attribute that AuthenticatedProjectResolver reads back).
 *
 * Requires an `$em` EntityManagerInterface property on the using class.
 */
trait McpTokenScenario
{
    /** Simulates a request authenticated by an MCP token bound to $project. */
    private function actAsMcpTokenBoundTo(Project $project): void
    {
        [$token] = ApiToken::issue($project->owner, 'mcp', ApiTokenScope::Mcp);
        $this->em->persist($token);
        $project->mcpToken = $token;
        $this->em->flush();

        $this->setSecurityTokenForApiToken($project->owner, $token);
    }

    /** Simulates a request authenticated by an MCP token bound to NO project. */
    private function actAsUnboundMcpToken(User $user): void
    {
        [$token] = ApiToken::issue($user, 'mcp-unbound', ApiTokenScope::Mcp);
        $this->em->persist($token);
        $this->em->flush();

        $this->setSecurityTokenForApiToken($user, $token);
    }

    private function setSecurityTokenForApiToken(User $user, ApiToken $apiToken): void
    {
        $securityToken = new PostAuthenticationToken($user, 'api', $user->getRoles());
        $securityToken->setAttribute(ApiTokenAuthenticator::API_TOKEN_ID_ATTR, (string) $apiToken->id);
        $tokenStorage = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokenStorage);
        $tokenStorage->setToken($securityToken);
    }
}
