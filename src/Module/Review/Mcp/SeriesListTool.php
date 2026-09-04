<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Mcp\ResolvesBoundProject;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Review\Repository\SeriesRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Reads the series of the project the authenticating token is bound to.
 *
 * The highest ordinal is what makes appending to a series possible in one call:
 * without it an agent has to page the document list to find out which number is
 * free.
 */
#[McpTool(name: 'series_list', description: 'List every series in the token\'s project, with how many documents each holds and the highest position number in use. Read this before you place a document, so a new item continues an existing series and takes the next free number instead of coining a near-duplicate name.')]
final readonly class SeriesListTool
{
    use ResolvesBoundProject;

    public function __construct(
        private SeriesRepository $series,
        private AuthenticatedProjectResolver $projectResolver,
    ) {
    }

    /**
     * The list is wrapped in a `series` object key because the MCP spec requires
     * a tool result's `structuredContent` to be a JSON object, not a bare array.
     *
     * @return array{series: list<array{name: string, documentCount: int, highestOrdinal: ?int}>}
     */
    public function __invoke(): array
    {
        try {
            $project = $this->requireBoundProject($this->projectResolver);

            return [
                'series' => array_map(
                    static fn (array $row): array => [
                        'name' => $row['series']->name,
                        'documentCount' => $row['documentCount'],
                        'highestOrdinal' => $row['highestOrdinal'],
                    ],
                    $this->series->findByProjectWithDocumentCounts($project),
                ),
            ];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The series list could not be read. The error has been logged.', previous: $e);
        }
    }
}
