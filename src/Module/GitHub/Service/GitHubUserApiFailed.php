<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

/** GitHub did not answer a user call usefully. The message is safe to log and never holds a token. */
final class GitHubUserApiFailed extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?int $status = null,
    ) {
        parent::__construct($reason);
    }
}
