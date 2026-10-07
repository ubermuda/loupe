<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Exception\DomainErrors;
use App\Module\Board\Command\AddBoardColumnCommand;
use App\Module\Board\Command\AddBoardColumnHandler;
use App\Module\Board\Command\ListBoardColumnsCommand;
use App\Module\Board\Command\ListBoardColumnsHandler;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * @phpstan-import-type BoardColumnSummary from BoardColumnPayload
 */
#[McpTool(name: self::NAME, description: 'Add a column to the project board. The new column goes after the last column, and it is not terminal. Its slug comes from the label: lowercase letters, digits and hyphens. The slug is the value you pass as status to the card tools. To make the column terminal, call column_update after this. The result names the slug of the new column and lists every column of the board.')]
final readonly class ColumnCreateTool
{
    public const string NAME = 'column_create';

    public function __construct(
        private BoardSubjectResolver $subjects,
        private AddBoardColumnHandler $addColumn,
        private ListBoardColumnsHandler $listColumns,
        private BoardColumnPayload $columns,
        private BoardToolErrorMessages $errorMessages,
    ) {
    }

    /**
     * @param string $label the name a person sees on the board; the slug comes from it
     *
     * @return array{column: string, columns: list<BoardColumnSummary>}
     */
    public function __invoke(string $label): array
    {
        try {
            $project = $this->subjects->requireProject();
            $column = ($this->addColumn)(new AddBoardColumnCommand($project, $label));
            $view = ($this->listColumns)(new ListBoardColumnsCommand($project));

            return ['column' => $column->slug, 'columns' => $this->columns->forColumns($view->columns)];
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The column could not be added. The error has been logged.', previous: $e);
        }
    }
}
