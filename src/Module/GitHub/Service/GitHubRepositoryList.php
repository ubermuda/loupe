<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\GitHub\GitHubRepositoryRef;

final readonly class GitHubRepositoryList
{
    /**
     * @param list<GitHubRepositoryRef> $repositories
     * @param bool                      $complete     false when GitHub may hold more pages than were read
     */
    public function __construct(
        public array $repositories,
        public bool $complete,
    ) {
    }
}
