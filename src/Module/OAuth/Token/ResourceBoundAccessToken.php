<?php

declare(strict_types=1);

namespace App\Module\OAuth\Token;

use Lcobucci\JWT\Token;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

/**
 * An access token whose JWT audience is a resource URI. League always writes
 * the client id there, so this replaces its JWT conversion. RFC 9068 names
 * the client in the client_id claim instead.
 */
final class ResourceBoundAccessToken implements AccessTokenEntityInterface
{
    use AccessTokenTrait;
    use EntityTrait;
    use TokenEntityTrait;

    /** @param non-empty-string $audience */
    public function __construct(
        public readonly string $audience,
    ) {
    }

    #[\Override]
    public function toString(): string
    {
        return $this->jwt()->toString();
    }

    private function jwt(): Token
    {
        $this->initJwtConfiguration();
        $now = new \DateTimeImmutable();

        return $this->jwtConfiguration->builder()
            ->permittedFor($this->audience)
            ->identifiedBy($this->getIdentifier())
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($this->getExpiryDateTime())
            ->relatedTo($this->getSubjectIdentifier())
            ->withClaim('client_id', $this->getClient()->getIdentifier())
            ->withClaim('scopes', $this->getScopes())
            ->getToken($this->jwtConfiguration->signer(), $this->jwtConfiguration->signingKey());
    }
}
