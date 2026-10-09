<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

/** The answer to "give me a working access token for this user". */
final readonly class GitHubUserTokenResult
{
    /** @param ?non-empty-string $accessToken set only when the status is Fresh */
    private function __construct(
        public GitHubUserTokenStatus $status,

        #[\SensitiveParameter]
        public ?string $accessToken = null,
    ) {
    }

    /** @param non-empty-string $accessToken */
    public static function fresh(#[\SensitiveParameter] string $accessToken): self
    {
        return new self(GitHubUserTokenStatus::Fresh, $accessToken);
    }

    public static function without(GitHubUserTokenStatus $status): self
    {
        return new self($status);
    }
}
