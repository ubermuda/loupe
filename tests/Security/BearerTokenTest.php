<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\ApiTokenRepository;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Security\ApiTokenAuthenticator;
use App\Module\OAuth\Security\OAuthAccessTokenAuthenticator;
use App\Module\OAuth\Service\McpResource;
use League\Bundle\OAuth2ServerBundle\Manager\AccessTokenManagerInterface;
use App\Module\Project\Repository\ProjectRepository;
use App\Security\BearerToken;
use App\Tests\Support\SilentAuditor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The two bearer authenticators share the token firewalls, and the first one
 * that supports a request answers its failure. Their supports() must split
 * every bearer between them with no overlap.
 */
final class BearerTokenTest extends TestCase
{
    private const string JWT = 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl';

    public function test_a_static_token_goes_to_the_api_token_authenticator_only(): void
    {
        [, $raw] = ApiToken::issue(new User(fullName: 'A', email: 'a@example.com', password: 'x'), 'label', ApiTokenScope::Mcp);
        $request = self::bearer($raw);

        self::assertTrue($this->apiTokenAuthenticator()->supports($request));
        self::assertFalse($this->oauthAuthenticator()->supports($request));
    }

    public function test_a_jwt_goes_to_the_oauth_authenticator_only(): void
    {
        $request = self::bearer(self::JWT);

        self::assertFalse($this->apiTokenAuthenticator()->supports($request));
        self::assertTrue($this->oauthAuthenticator()->supports($request));
    }

    public function test_no_bearer_goes_to_neither(): void
    {
        $request = new Request();

        self::assertFalse($this->apiTokenAuthenticator()->supports($request));
        self::assertFalse($this->oauthAuthenticator()->supports($request));
        self::assertFalse(ApiTokenAuthenticator::carriesBearerToken($request));
    }

    public function test_any_bearer_is_charged_by_the_authentication_limiter(): void
    {
        self::assertTrue(ApiTokenAuthenticator::carriesBearerToken(self::bearer(self::JWT)));
        self::assertTrue(ApiTokenAuthenticator::carriesBearerToken(self::bearer('garbage')));
    }

    public function test_the_jwt_shape(): void
    {
        self::assertTrue(BearerToken::isJwt(self::JWT));
        self::assertFalse(BearerToken::isJwt(str_repeat('a', 64)));
        self::assertFalse(BearerToken::isJwt('a.b'));
        self::assertFalse(BearerToken::isJwt('a.b.c.d'));
        self::assertFalse(BearerToken::isJwt('a b.c.d'));
    }

    private static function bearer(string $token): Request
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer '.$token);

        return $request;
    }

    private function apiTokenAuthenticator(): ApiTokenAuthenticator
    {
        return new ApiTokenAuthenticator($this->createStub(ApiTokenRepository::class), new NullLogger(), SilentAuditor::create());
    }

    private function oauthAuthenticator(): OAuthAccessTokenAuthenticator
    {
        return new OAuthAccessTokenAuthenticator(
            static fn () => throw new \LogicException('supports() must not build the resource server'),
            $this->createStub(HttpMessageFactoryInterface::class),
            $this->createStub(UserRepository::class),
            $this->createStub(ProjectRepository::class),
            $this->createStub(AccessTokenManagerInterface::class),
            new McpResource('https://loupe.example'),
            new NullLogger(),
            SilentAuditor::create(),
        );
    }
}
