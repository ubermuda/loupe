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
 */
trait ResolvesBoundProject
{
    private function requireBoundProject(AuthenticatedProjectResolver $projectResolver): Project
    {
        return $projectResolver->resolveMcpProject()
            ?? throw new ToolCallException('MCP token is not bound to a project. Mint a project token from the Connect page.');
    }
}
