<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Security;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Repository\ApiTokenRepository;
use App\Module\Account\Security\ApiTokenAuthenticator;
use App\Module\Account\Security\AuthenticatedApiTokenResolver;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Uid\Uuid;

final class AuthenticatedApiTokenResolverTest extends TestCase
{
    private ApiToken&Stub $apiToken;
    private ApiTokenRepository&Stub $apiTokens;

    protected function setUp(): void
    {
        $this->apiToken = $this->createStub(ApiToken::class);
        $this->apiTokens = $this->createStub(ApiTokenRepository::class);
        $this->apiTokens->method('find')->willReturn($this->apiToken);
    }

    public function test_resolves_the_token_named_by_the_security_token_attribute(): void
    {
        $id = (string) Uuid::v7();

        $apiTokens = $this->createStub(ApiTokenRepository::class);
        $apiTokens->method('find')
            ->willReturnCallback(fn (mixed $lookup): ?ApiToken => (string) $lookup === $id ? $this->apiToken : null);

        $resolver = new AuthenticatedApiTokenResolver(new TokenStorage(), $apiTokens);

        self::assertSame($this->apiToken, $resolver->forSecurityToken($this->securityTokenWith($id)));
    }

    public function test_resolves_nothing_without_a_security_token(): void
    {
        $resolver = new AuthenticatedApiTokenResolver(new TokenStorage(), $this->apiTokens);

        self::assertNull($resolver->forSecurityToken(null));
    }

    public function test_resolves_nothing_when_the_security_token_carries_no_api_token_id(): void
    {
        $securityToken = $this->createStub(TokenInterface::class);
        $securityToken->method('hasAttribute')->willReturn(false);

        $resolver = new AuthenticatedApiTokenResolver(new TokenStorage(), $this->apiTokens);

        self::assertNull($resolver->forSecurityToken($securityToken));
    }

    public function test_resolves_nothing_when_the_attribute_is_not_a_string(): void
    {
        $resolver = new AuthenticatedApiTokenResolver(new TokenStorage(), $this->apiTokens);

        self::assertNull($resolver->forSecurityToken($this->securityTokenWith(42)));
    }

    public function test_resolves_nothing_when_the_attribute_is_not_a_uuid(): void
    {
        $resolver = new AuthenticatedApiTokenResolver(new TokenStorage(), $this->apiTokens);

        self::assertNull($resolver->forSecurityToken($this->securityTokenWith('not-a-uuid')));
    }

    public function test_current_reads_the_security_token_from_storage(): void
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken($this->securityTokenWith((string) Uuid::v7()));

        $resolver = new AuthenticatedApiTokenResolver($tokenStorage, $this->apiTokens);

        self::assertSame($this->apiToken, $resolver->current());
    }

    private function securityTokenWith(mixed $apiTokenId): TokenInterface&Stub
    {
        $securityToken = $this->createStub(TokenInterface::class);
        $securityToken->method('hasAttribute')
            ->willReturnCallback(static fn (string $name): bool => ApiTokenAuthenticator::API_TOKEN_ID_ATTR === $name);
        $securityToken->method('getAttribute')->willReturn($apiTokenId);

        return $securityToken;
    }
}
