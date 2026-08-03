<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Mcp\ResolvesBoundProject;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Review\Repository\TagRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Reads the tag vocabulary of the project the authenticating token is bound to.
 *
 * Because tags are created implicitly, nothing rejects a near-duplicate name —
 * an agent that coins "design-spec" alongside an established "design" gets no
 * error, and the two simply stop grouping anything together. The document count
 * is what makes that visible: it separates a convention in use from a name
 * somebody minted once.
 */
#[McpTool(name: 'project_list_tags', description: 'List every tag in the token\'s project with how many documents carry it. Read this before tagging so a batch joins the existing vocabulary instead of coining near-duplicates of it.')]
final readonly class ProjectListTagsTool
{
    use ResolvesBoundProject;

    public function __construct(
        private TagRepository $tags,
        private AuthenticatedProjectResolver $projectResolver,
    ) {
    }

    /**
     * The list is wrapped in a `tags` object key because the MCP spec requires a
     * tool result's `structuredContent` to be a JSON object, not a bare array.
     *
     * @return array{tags: list<array{name: string, documentCount: int}>}
     */
    public function __invoke(): array
    {
        try {
            $project = $this->requireBoundProject($this->projectResolver);

            return [
                'tags' => array_map(
                    static fn (array $row): array => [
                        'name' => $row['tag']->name,
                        'documentCount' => $row['documentCount'],
                    ],
                    $this->tags->findByProjectWithDocumentCounts($project),
                ),
            ];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The tag list could not be read. The error has been logged.', previous: $e);
        }
    }
}
