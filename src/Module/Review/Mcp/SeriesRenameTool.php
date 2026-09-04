<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Exception\DomainErrors;
use App\Mcp\ResolvesBoundProject;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Review\Command\RenameSeriesCommand;
use App\Module\Review\Command\RenameSeriesHandler;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Repository\SeriesRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Renames one series of the project the authenticating token is bound to.
 *
 * The series is looked up inside that project, so a token can never reach one
 * belonging to another project. This is the same scoping the tag tools apply.
 */
#[McpTool(name: 'series_rename', description: 'Rename a series of the token\'s project. Every document in it keeps its position. The new name must not already belong to another series, because two series carry two independent numberings and merging them would put two documents on the same number.')]
final readonly class SeriesRenameTool
{
    use ResolvesBoundProject;

    public function __construct(
        private RenameSeriesHandler $renameSeries,
        private SeriesRepository $series,
        private DocumentRepository $documents,
        private AuthenticatedProjectResolver $projectResolver,
        private ToolCallErrorMessages $errorMessages,
    ) {
    }

    /**
     * @param string $series  The current name of the series to rename
     * @param string $newName The name it takes, lowercased on write
     *
     * @return array{series: string, documentCount: int}
     */
    public function __invoke(string $series, string $newName): array
    {
        try {
            $project = $this->requireBoundProject($this->projectResolver);

            $found = $this->series->findOneByProjectAndName($project, $series);
            if (null === $found) {
                throw new ToolCallException(\sprintf('Series "%s" not found in this project.', $series));
            }

            $renamed = ($this->renameSeries)(new RenameSeriesCommand($found, $newName));

            return [
                'series' => $renamed->name,
                'documentCount' => $this->documents->countBySeries($renamed),
            ];
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The series could not be renamed. The error has been logged.', previous: $e);
        }
    }
}
