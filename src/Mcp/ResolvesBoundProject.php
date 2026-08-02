<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Module\Project\Entity\Project;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use Mcp\Exception\ToolCallException;

/**
 * Resolves the project bound to the authenticating MCP token, or rejects the
 * tool call when the token is not bound to one. Shared by every MCP tool so the
 * rejection message has a single source of truth.
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
        return $projectResolver->resolveMcpProject()
            ?? throw new ToolCallException('MCP token is not bound to a project. Mint a project token from the Connect page.');
    }
}
