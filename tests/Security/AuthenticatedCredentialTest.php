<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Module\Account\Entity\User;
use App\Security\AuthenticatedCredential;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

final class AuthenticatedCredentialTest extends TestCase
{
    public function test_reads_the_credential_an_authenticator_attached(): void
    {
        $credential = new AuthenticatedCredential('grant-1', ['ROLE_API_MCP']);
        $securityToken = $this->securityToken();
        $securityToken->setAttribute(AuthenticatedCredential::ATTRIBUTE, $credential);

        self::assertSame($credential, AuthenticatedCredential::of($securityToken));
    }

    public function test_reads_nothing_without_a_security_token(): void
    {
        self::assertNull(AuthenticatedCredential::of(null));
    }

    public function test_reads_nothing_from_a_session_login(): void
    {
        self::assertNull(AuthenticatedCredential::of($this->securityToken()));
    }

    public function test_ignores_an_attribute_that_is_not_a_credential(): void
    {
        $securityToken = $this->securityToken();
        $securityToken->setAttribute(AuthenticatedCredential::ATTRIBUTE, 'grant-1');

        self::assertNull(AuthenticatedCredential::of($securityToken));
    }

    private function securityToken(): PostAuthenticationToken
    {
        $user = new User(fullName: 'U', email: 'credential@example.com', password: 'x');

        return new PostAuthenticationToken($user, 'api', $user->getRoles());
    }
}
