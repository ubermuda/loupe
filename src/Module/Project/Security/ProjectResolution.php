<?php

declare(strict_types=1);

namespace App\Module\Project\Security;

use App\Module\Project\Entity\Project;

/**
 * The project a request acts on, or why it has none.
 *
 * `covered` lists the projects the credential does reach, so a caller can say
 * what to choose from. It holds only projects the credential's own user owns,
 * so naming them leaks nothing the caller could not already list. `requested`
 * holds the trimmed header value, so a refusal can quote it back.
 */
final readonly class ProjectResolution
{
    /** @param list<Project> $covered */
    private function __construct(
        public ?Project $project,
        public ?ProjectRefusal $refusal,
        public array $covered,
        public ?string $requested = null,
    ) {
    }

    public static function of(Project $project): self
    {
        return new self($project, null, [$project]);
    }

    /** @param list<Project> $covered */
    public static function refused(ProjectRefusal $refusal, array $covered = [], ?string $requested = null): self
    {
        return new self(null, $refusal, $covered, $requested);
    }
}
