<?php

declare(strict_types=1);

namespace App\Module\Project\Mcp;

use App\Mcp\ResolvesBoundProject;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Answers which project the connection acts on.
 *
 * Every other tool resolves the project silently, so an agent can write to the
 * wrong project and read a success. This makes the binding something an agent
 * can state and a person can check before a write.
 *
 * It reads the resolved project and calls no handler, because the resolver
 * already holds the entity and there is nothing to query.
 */
#[McpTool(name: 'project_current', description: 'Report which Loupe project this connection acts on, with its id, slug and name. Call it before a write when you are not certain, and state the project in what you report back. A connection reaches exactly one project, so a write cannot be redirected by an argument.')]
final readonly class ProjectCurrentTool
{
    use ResolvesBoundProject;

    public const string NAME = 'project_current';

    public function __construct(
        private AuthenticatedProjectResolver $projectResolver,
    ) {
    }

    /**
     * The slug is null on a project created before the column existed, which is
     * why the id is the identifier to quote.
     *
     * @return array{project: array{id: string, slug: string|null, name: string}}
     */
    public function __invoke(): array
    {
        try {
            $project = $this->requireBoundProject($this->projectResolver);

            return [
                'project' => [
                    'id' => (string) $project->id,
                    'slug' => $project->slug,
                    'name' => $project->name,
                ],
            ];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The current project could not be read. The error has been logged.', previous: $e);
        }
    }
}
