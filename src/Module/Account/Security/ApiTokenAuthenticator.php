<?php

declare(strict_types=1);

namespace App\Module\Account\Security;

use App\Module\Account\Repository\ApiTokenRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class ApiTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly ApiTokenRepository $apiTokens,
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

        return new SelfValidatingPassport(new UserBadge($token->owner->getUserIdentifier(), fn () => $token->owner));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
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
