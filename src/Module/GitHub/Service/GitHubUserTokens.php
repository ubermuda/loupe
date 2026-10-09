<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

/**
 * What GitHub hands out for a user. A refresh token and its expiry are null
 * when the App does not expire user tokens.
 */
final readonly class GitHubUserTokens
{
    /**
     * @param non-empty-string  $accessToken
     * @param ?non-empty-string $refreshToken
     */
    public function __construct(
        #[\SensitiveParameter]
        public string $accessToken,

        #[\SensitiveParameter]
        public ?string $refreshToken,
        public ?\DateTimeImmutable $accessTokenExpiresAt,
        public ?\DateTimeImmutable $refreshTokenExpiresAt,
    ) {
    }

    public function canRefresh(): bool
    {
        return null !== $this->refreshToken && null !== $this->accessTokenExpiresAt && null !== $this->refreshTokenExpiresAt;
    }
}
