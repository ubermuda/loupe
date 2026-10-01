<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

/** GitHub did not answer an App call usefully. The message is safe to log and never holds a key, a JWT or a token. */
final class GitHubAppApiFailed extends \RuntimeException
{
    /**
     * @param ?int    $retryAfterSeconds how long GitHub asks a rate-limited caller to wait, when it says
     * @param ?string $graphqlType       the `type` of the GraphQL error that failed the call, such as `FORBIDDEN`
     */
    public function __construct(
        public readonly string $reason,
        public readonly ?int $status = null,
        public readonly bool $rateLimited = false,
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?string $graphqlType = null,
    ) {
        parent::__construct($reason);
    }
}
