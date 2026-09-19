<?php

declare(strict_types=1);

namespace App\Module\OAuth\Security;

use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\OAuth\Scope\GrantedScope;
use App\Module\OAuth\Service\McpResource;
use App\Module\Project\Repository\ProjectRepository;
use App\Security\AuthenticatedCredential;
use App\Security\BearerToken;
use League\Bundle\OAuth2ServerBundle\Manager\AccessTokenManagerInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

/**
 * Accepts an OAuth access token on the token firewalls, beside the static
 * ApiTokenAuthenticator. League checks the signature, the expiry and the
 * revoked flag in the database. The grant then maps to the same role a static
 * token of that scope carries.
 */
#[WithMonologChannel('app_security')]
final class OAuthAccessTokenAuthenticator extends AbstractAuthenticator
{
    /**
     * @param \Closure(): ResourceServer $resourceServer lazy, so a missing key
     *                                                   fails only OAuth requests
     */
    public function __construct(
        #[AutowireServiceClosure('league.oauth2_server.resource_server')]
        private readonly \Closure $resourceServer,

        #[Autowire(service: 'league.oauth2_server.factory.psr_http')]
        private readonly HttpMessageFactoryInterface $psrRequests,
        private readonly UserRepository $users,
        private readonly ProjectRepository $projects,
        private readonly AccessTokenManagerInterface $accessTokens,
        private readonly McpResource $mcpResource,
        private readonly LoggerInterface $logger,
        private readonly Auditor $auditor,
    ) {
    }

    /**
     * The id an OAuth credential keys its rate limits on. The access token id
     * changes at every refresh, so it would reset the limits. The client, the
     * user and the project do not change for the life of a grant.
     */
    public static function credentialId(string $clientId, string $userId, ?Uuid $projectId): string
    {
        return \sprintf('oauth:%s:%s:%s', $clientId, $userId, $projectId?->toRfc4122() ?? '-');
    }

    #[\Override]
    public function supports(Request $request): bool
    {
        $bearer = BearerToken::of($request);

        return null !== $bearer && BearerToken::isJwt($bearer);
    }

    #[\Override]
    public function authenticate(Request $request): Passport
    {
        try {
            $validated = ($this->resourceServer)()->validateAuthenticatedRequest($this->psrRequests->createRequest($request));
        } catch (OAuthServerException $e) {
            throw new AuthenticationException('Invalid OAuth access token.', 0, $e);
        }

        // League reports the first audience as the client id. An mcp token's audience is the MCP endpoint.
        $audience = $validated->getAttribute('oauth_client_id');
        $tokenId = $validated->getAttribute('oauth_access_token_id');
        $userId = $validated->getAttribute('oauth_user_id');
        $scopes = $validated->getAttribute('oauth_scopes');
        $clientId = \is_string($tokenId) ? $this->accessTokens->find($tokenId)?->getClient()->getIdentifier() : null;
        if (!\is_string($audience) || null === $clientId || !\is_string($userId) || !Uuid::isValid($userId) || !\is_array($scopes)) {
            throw new AuthenticationException('Malformed OAuth access token.');
        }

        $granted = GrantedScope::fromScopes(array_values(array_filter($scopes, \is_string(...))));
        if ($audience !== (ApiTokenScope::Mcp === $granted?->scope ? $this->mcpResource->uri : $clientId)) {
            throw new AuthenticationException('The OAuth access token is for another audience.');
        }

        $user = $this->users->find(Uuid::fromString($userId));
        if (null === $granted || null === $user) {
            throw new AuthenticationException('The OAuth grant no longer applies.');
        }

        if (null !== $granted->projectId) {
            $project = $this->projects->find($granted->projectId);
            if (null === $project || $project->owner->id?->toRfc4122() !== $user->id?->toRfc4122()) {
                throw new AuthenticationException('The OAuth grant no longer applies.');
            }
        }

        $passport = new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn (): User => $user));
        $passport->setAttribute(AuthenticatedCredential::ATTRIBUTE, new AuthenticatedCredential(
            self::credentialId($clientId, $userId, $granted->projectId),
            $granted->scope->role(),
            $granted->projectId,
        ));

        return $passport;
    }

    #[\Override]
    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        $user = $passport->getUser();
        $credential = $passport->getAttribute(AuthenticatedCredential::ATTRIBUTE);
        if (!$credential instanceof AuthenticatedCredential) {
            throw new \LogicException('credential missing on passport after authentication.');
        }

        $token = new PostAuthenticationToken($user, $firewallName, [...$user->getRoles(), $credential->scopeRole]);
        $token->setAttribute(AuthenticatedCredential::ATTRIBUTE, $credential);

        return $token;
    }

    #[\Override]
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    #[\Override]
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $this->auditor->record(
            'oauth.access_token_authentication_failed',
            AuditOutcome::Refused,
            category: Auditor::CATEGORY_SECURITY,
        );

        // No token material: these records go to stderr in production.
        $this->logger->warning('oauth.access_token_authentication_failed', [
            'path' => $request->getPathInfo(),
            'ip' => $request->getClientIp(),
            'reason' => $exception->getMessage(),
        ]);

        return new Response('{"error":"unauthorized"}', Response::HTTP_UNAUTHORIZED, ['Content-Type' => 'application/json']);
    }
}
