<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

/** The GitHub account that a user token belongs to. */
final readonly class GitHubUserProfile
{
    /** @param non-empty-string $login */
    public function __construct(
        public int $id,
        public string $login,
    ) {
    }
}
