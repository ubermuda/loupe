<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Module\Account\Entity\User;
use App\Security\ApiTokenRateLimitKey;
use App\Security\AuthenticatedCredential;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

final class ApiTokenRateLimitKeyTest extends TestCase
{
    public function test_keys_on_the_credential_id(): void
    {
        $key = $this->keyFor(new AuthenticatedCredential('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', 'ROLE_API_AGENT'));

        self::assertSame('token:0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', $key->forRequest($this->request()));
    }

    public function test_falls_back_to_the_client_address_without_a_credential(): void
    {
        self::assertSame('ip:203.0.113.7', $this->keyFor(null)->forRequest($this->request()));
    }

    private function keyFor(?AuthenticatedCredential $credential): ApiTokenRateLimitKey
    {
        $tokenStorage = new TokenStorage();
        $user = new User(fullName: 'U', email: 'rate-key@example.com', password: 'x');
        $securityToken = new PostAuthenticationToken($user, 'api', $user->getRoles());
        if (null !== $credential) {
            $securityToken->setAttribute(AuthenticatedCredential::ATTRIBUTE, $credential);
        }
        $tokenStorage->setToken($securityToken);

        return new ApiTokenRateLimitKey($tokenStorage);
    }

    private function request(): Request
    {
        return Request::create('/api/agent/sites', server: ['REMOTE_ADDR' => '203.0.113.7']);
    }
}
