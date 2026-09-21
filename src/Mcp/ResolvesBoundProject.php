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

        return match ($resolution->refusal) {
            ProjectRefusal::HeaderMalformed => \sprintf('The %s header must be a project id.', $header),
            ProjectRefusal::HeaderNotCovered => \sprintf('The %s header names a project this login does not cover. %s', $header, self::covered($resolution)),
            ProjectRefusal::SeveralProjectsAndNoHeader => \sprintf('This login covers several projects, so the project must be named in the %s header. %s', $header, self::covered($resolution)),
            default => 'MCP token is not bound to a project. Mint a project token from the Connect page.',
        };
    }

    private static function covered(ProjectResolution $resolution): string
    {
        if ([] === $resolution->covered) {
            return 'It covers no project.';
        }

        $names = array_map(
            static fn (Project $project): string => \sprintf('%s (%s)', $project->name, (string) $project->id),
            $resolution->covered,
        );

        return 'It covers: '.implode(', ', $names).'.';
    }
}
