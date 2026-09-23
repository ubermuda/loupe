<?php

declare(strict_types=1);

namespace App\Module\GitHub;

/** A repository as a delivery names it. The id survives a rename and a transfer, and the path does not. */
final readonly class GitHubRepositoryRef
{
    /** @param non-empty-string $fullName */
    public function __construct(
        public int $id,
        public string $fullName,
    ) {
    }

    public function externalId(): string
    {
        return (string) $this->id;
    }
}
