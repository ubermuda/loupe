<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Module\Account\Repository\UserRepository;
use App\Module\OAuth\Security\OAuthAccessTokenAuthenticator;
use App\Module\OAuth\Service\McpResource;
use App\Module\Project\Repository\ProjectRepository;
use App\Security\BearerToken;
use App\Tests\Support\SilentAuditor;
use League\Bundle\OAuth2ServerBundle\Manager\AccessTokenManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * One authenticator holds the token firewalls, so it must answer every bearer.
 * A bearer it declined would reach no authenticator at all, and the firewall
 * would answer it without the audit record a refusal owes.
 */
final class BearerTokenTest extends TestCase
{
    private const string JWT = 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl';

    public function test_every_bearer_reaches_the_oauth_authenticator(): void
    {
        self::assertTrue($this->authenticator()->supports(self::bearer(self::JWT)));
        self::assertTrue($this->authenticator()->supports(self::bearer(str_repeat('a', 64))));
        self::assertTrue($this->authenticator()->supports(self::bearer('garbage')));
    }

    public function test_a_request_with_no_bearer_is_left_alone(): void
    {
        self::assertFalse($this->authenticator()->supports(new Request()));
        self::assertNull(BearerToken::of(new Request()));
    }

    public function test_the_bearer_is_read_after_the_scheme(): void
    {
        self::assertSame(self::JWT, BearerToken::of(self::bearer(self::JWT)));
    }

    private function authenticator(): OAuthAccessTokenAuthenticator
    {
        return new OAuthAccessTokenAuthenticator(
            static fn () => throw new \LogicException('supports() must decide before the resource server is built.'),
            self::createStub(HttpMessageFactoryInterface::class),
            self::createStub(UserRepository::class),
            self::createStub(ProjectRepository::class),
            self::createStub(AccessTokenManagerInterface::class),
            new McpResource('https://loupe.example'),
            new NullLogger(),
            SilentAuditor::create(),
        );
    }

    private static function bearer(string $token): Request
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer '.$token);

        return $request;
    }
}
