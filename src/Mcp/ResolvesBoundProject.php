<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Module\Project\Entity\Project;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Project\Security\ProjectRefusal;
use App\Module\Project\Security\ProjectResolution;
use Mcp\Exception\ToolCallException;

/**
 * Resolves the project the MCP call acts on, or rejects the call when no
 * project can be resolved. Shared by every MCP tool so the rejection has a
 * single source of truth.
 *
 * Deliberately knows nothing about what lives inside a project. A lookup that
 * reaches a module's entities belongs to that module: inherited here, a tool of
 * one module could resolve another module's `Comment` against the wrong table
 * and fail at runtime as "not found" rather than at compile time.
 */
trait ResolvesBoundProject
{
    private function requireBoundProject(AuthenticatedProjectResolver $projectResolver): Project
    {
        $resolution = $projectResolver->mcpResolution();

        return $resolution->project ?? throw new ToolCallException(self::refusalMessage($resolution));
    }

    /**
     * A tool error rather than a transport failure, so a client cannot read a
     * refusal as a dead session. A dead session answers 404 with a JSON-RPC
     * body, and this travels inside a 200.
     */
    private static function refusalMessage(ProjectResolution $resolution): string
    {
        $header = AuthenticatedProjectResolver::PROJECT_HEADER;
        $requested = self::requested($resolution);

        return match ($resolution->refusal) {
            ProjectRefusal::SeveralProjectsAndNoHeader => \sprintf('This login covers several projects, and this request names none. Run `loupe init` in the repository to choose one, then call the tool again. An HTTP client names the project in the %s header instead. %s', $header, self::covered($resolution)),
            ProjectRefusal::NoProject => 'This login covers no project yet. Create a project in Loupe, then call the tool again.',
            ProjectRefusal::HeaderNotCovered => \sprintf('This login does not cover the project this request names%s. Run `loupe init --force` in the repository to choose a project it covers. `loupe mcp` takes the project from `.loupe.yaml`, or from its `--project` option. An HTTP client sets the %s header. %s', $requested, $header, self::covered($resolution)),
            ProjectRefusal::HeaderNotBound => \sprintf('This credential is bound to one project, and the %s header names another%s. Remove the header, or set it to %s.', $header, $requested, self::named($resolution->covered)),
            ProjectRefusal::HeaderMalformed => \sprintf('The project this request names is not a project id%s. Run `loupe init` in the repository to choose one. `loupe mcp --project` and the %s header take an id such as 0192f3c4-5d6e-7f80-9123-456789abcdef.', $requested, $header),
            default => 'This credential reaches no project. Sign in again with `loupe login`, or reconnect the app that holds the credential.',
        };
    }

    /** The value the request sent, quoted, and cut so a long header cannot flood the message. */
    private static function requested(ProjectResolution $resolution): string
    {
        return null === $resolution->requested ? '' : \sprintf(' ("%s")', mb_substr($resolution->requested, 0, 64));
    }

    private static function covered(ProjectResolution $resolution): string
    {
        return [] === $resolution->covered
            ? 'It covers no project.'
            : 'It covers: '.self::named($resolution->covered).'.';
    }

    /** @param list<Project> $projects */
    private static function named(array $projects): string
    {
        $names = array_map(
            static fn (Project $project): string => \sprintf('%s (%s)', $project->name, (string) $project->id),
            $projects,
        );

        return implode(', ', $names);
    }
}
