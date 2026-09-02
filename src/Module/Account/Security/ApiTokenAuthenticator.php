<?php

declare(strict_types=1);

namespace App\Module\Account\Security;

use App\Module\Account\Repository\ApiTokenRepository;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

#[WithMonologChannel('app_security')]
final class ApiTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    private const string SCOPE_ROLE_ATTR = 'scopeRole';

    public const string API_TOKEN_ID_ATTR = 'apiTokenId';

    // The widget token is embedded on every page of a customer's site, so a
    // Bearer request hits this authenticator on every page view. Recording
    // usage only when the last record is older than this window turns a
    // per-request UPDATE against one hot row into an occasional one.
    private const int STALE_USAGE_WINDOW_MINUTES = 15;

    public function __construct(
        private readonly ApiTokenRepository $apiTokens,
        private readonly LoggerInterface $logger,
        private readonly Auditor $auditor,
    ) {
    }

    public function supports(Request $request): bool
    {
        return str_starts_with((string) $request->headers->get('Authorization', ''), 'Bearer ');
    }

    public function authenticate(Request $request): Passport
    {
        $raw = substr((string) $request->headers->get('Authorization'), 7);
        $token = $this->apiTokens->findOneByRawToken($raw);
        if (null === $token) {
            throw new AuthenticationException('Invalid API token.');
        }

        $now = new \DateTimeImmutable();
        $staleThreshold = $now->modify(sprintf('-%d minutes', self::STALE_USAGE_WINDOW_MINUTES));

        // The token entity is already hydrated, so a fresh timestamp is decided
        // here and costs no round trip at all. The UPDATE keeps the same
        // condition in SQL: two concurrent requests can both read a stale value.
        if (null === $token->lastUsedAt || $token->lastUsedAt < $staleThreshold) {
            $this->apiTokens->touchLastUsedAt(
                $token->id ?? throw new \LogicException('a persisted API token always has an id'),
                $now,
                $staleThreshold,
            );
        }

        $passport = new SelfValidatingPassport(new UserBadge($token->owner->getUserIdentifier(), fn () => $token->owner));
        $passport->setAttribute(self::SCOPE_ROLE_ATTR, $token->scope->role());
        $passport->setAttribute(self::API_TOKEN_ID_ATTR, (string) $token->id);

        return $passport;
    }

    #[\Override]
    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        $user = $passport->getUser();
        $scopeRole = $passport->getAttribute(self::SCOPE_ROLE_ATTR);
        if (!is_string($scopeRole)) {
            throw new \LogicException('scopeRole missing on passport after authentication.');
        }

        $authenticatedToken = new PostAuthenticationToken($user, $firewallName, [...$user->getRoles(), $scopeRole]);
        $apiTokenId = $passport->getAttribute(self::API_TOKEN_ID_ATTR);
        if (is_string($apiTokenId)) {
            $authenticatedToken->setAttribute(self::API_TOKEN_ID_ATTR, $apiTokenId);
        }

        return $authenticatedToken;
    }

    #[\Override]
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        // The one site that writes both, and the split is the reason: the
        // record states only that an API call was refused. The path, the client
        // IP and the framework's exception string are user-controlled or
        // personal data, so they stay diagnostics in the log line beside it.
        // The trail has no erasure path. No subject either, no token resolved.
        $this->auditor->record(
            'account.api_token.authentication_failed',
            AuditOutcome::Refused,
            category: Auditor::CATEGORY_SECURITY,
        );

        // Deliberately no token material, not even a prefix: these records go to
        // stderr in production, where a guessed-token log becomes the leak.
        $this->logger->warning('account.api_token.authentication_failed', [
            'path' => $request->getPathInfo(),
            'ip' => $request->getClientIp(),
            'reason' => $exception->getMessage(),
        ]);

        return new Response('{"error":"unauthorized"}', Response::HTTP_UNAUTHORIZED, ['Content-Type' => 'application/json']);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    /**
     * Called when an anonymous request hits a protected resource (no Authorization header).
     */
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new Response('{"error":"unauthorized"}', Response::HTTP_UNAUTHORIZED, ['Content-Type' => 'application/json']);
    }
}
