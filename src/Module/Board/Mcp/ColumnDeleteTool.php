<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Exception\DomainErrors;
use App\Module\Board\Command\DeleteBoardColumnCommand;
use App\Module\Board\Command\DeleteBoardColumnHandler;
use App\Module\Board\Command\ListBoardColumnsCommand;
use App\Module\Board\Command\ListBoardColumnsHandler;
use App\Module\Workflow\Contract\Actor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * @phpstan-import-type BoardColumnSummary from BoardColumnPayload
 */
#[McpTool(name: self::NAME, description: 'Delete a column of the project board. Name the column by its slug, from board_columns. A column that holds cards needs targetColumn, the slug of the column the cards move to. A moved card follows the rules of a move: a card that enters a terminal column gets a completion time, and a card that enters a column that is not terminal loses it. Nobody can delete Backlog, and a board keeps at least one terminal column. The result names the deleted slug, the number of moved cards and where they went, and lists every column that remains.')]
final readonly class ColumnDeleteTool
{
    public const string NAME = 'column_delete';

    public function __construct(
        private BoardSubjectResolver $subjects,
        private DeleteBoardColumnHandler $deleteColumn,
        private ListBoardColumnsHandler $listColumns,
        private BoardColumnPayload $columns,
        private BoardToolErrorMessages $errorMessages,
    ) {
    }

    /**
     * @param string      $slug         the slug of the column to delete, from board_columns
     * @param string|null $targetColumn the slug of the column the cards move to; needed only when the column holds cards
     *
     * @return array{deleted: string, movedCards: int, targetColumn: string|null, columns: list<BoardColumnSummary>}
     */
    public function __invoke(string $slug, ?string $targetColumn = null): array
    {
        try {
            $project = $this->subjects->requireProject();
            $column = $this->subjects->requireColumn($project, $slug, 'column', 'slug');
            $target = $this->subjects->optionalColumn($project, $targetColumn, 'column', 'targetColumn');

            $deleted = ($this->deleteColumn)(new DeleteBoardColumnCommand($column, Actor::Agent, $target));
            $view = ($this->listColumns)(new ListBoardColumnsCommand($project));

            return [
                'deleted' => $deleted->slug,
                'movedCards' => \count($deleted->movedCardIds),
                'targetColumn' => $deleted->targetSlug,
                'columns' => $this->columns->forColumns($view->columns),
            ];
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e, ['column' => 'slug', 'target' => 'targetColumn']);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The column could not be deleted. The error has been logged.', previous: $e);
        }
    }
}
