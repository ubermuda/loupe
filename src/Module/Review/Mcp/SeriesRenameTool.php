<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Exception\DomainErrors;
use App\Module\Review\Command\RenameSeriesCommand;
use App\Module\Review\Command\RenameSeriesHandler;
use App\Module\Review\Repository\DocumentRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Renames one series of the project the authenticating token is bound to.
 *
 * ReviewSubjectResolver scopes the lookup to that project and puts the rename
 * behind McpBoundProjectVoter, the same gate the document tools use.
 */
#[McpTool(name: 'series_rename', description: 'Rename a series of the token\'s project. Every document in it keeps its position. The name is stored as you spell it, and it must not already belong to another series, ignoring case, because two series carry two independent numberings and merging them would put two documents on the same number.')]
final readonly class SeriesRenameTool
{
    public function __construct(
        private RenameSeriesHandler $renameSeries,
        private ReviewSubjectResolver $subjects,
        private DocumentRepository $documents,
        private ToolCallErrorMessages $errorMessages,
    ) {
    }

    /**
     * @param string $series  The current name of the series to rename
     * @param string $newName The name it takes, stored as you spell it
     *
     * @return array{series: string, documentCount: int}
     */
    public function __invoke(string $series, string $newName): array
    {
        try {
            $found = $this->subjects->requireSeries($series);

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
