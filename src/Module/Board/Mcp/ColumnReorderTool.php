<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Exception\DomainErrors;
use App\Module\Board\Command\ListBoardColumnsCommand;
use App\Module\Board\Command\ListBoardColumnsHandler;
use App\Module\Board\Command\ReorderBoardColumnsCommand;
use App\Module\Board\Command\ReorderBoardColumnsHandler;
use App\Module\Board\Entity\BoardColumn;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * @phpstan-import-type BoardColumnSummary from BoardColumnPayload
 */
#[McpTool(name: self::NAME, description: 'Put the columns of the project board in a new order. Pass the slug of every column except backlog, each one once, in the order the board shows them from left to right. Backlog always comes first and never moves, so leave it out. board_columns lists the slugs. The result lists every column of the board in the new order.')]
final readonly class ColumnReorderTool
{
    public const string NAME = 'column_reorder';

    public function __construct(
        private BoardSubjectResolver $subjects,
        private ReorderBoardColumnsHandler $reorderColumns,
        private ListBoardColumnsHandler $listColumns,
        private BoardColumnPayload $columns,
        private BoardToolErrorMessages $errorMessages,
    ) {
    }

    /**
     * `string[]` not `list<string>`, for the reason on CardUpdateTool.
     *
     * @param string[] $order the slug of every column except backlog, each once, in the new order
     *
     * @return array{columns: list<BoardColumnSummary>}
     */
    public function __invoke(array $order): array
    {
        try {
            $project = $this->subjects->requireProject();

            $ids = [];
            foreach (($this->listColumns)(new ListBoardColumnsCommand($project))->columns as $column) {
                if (!$column->backlog) {
                    $ids[$column->slug] = (string) $column->id;
                }
            }
            $slugs = implode(', ', array_keys($ids));

            $wanted = [];
            foreach ($order as $slug) {
                if (BoardColumn::BACKLOG_SLUG === $slug) {
                    throw new ToolCallException('Backlog always comes first. Leave backlog out of order.');
                }
                if (!\is_string($slug) || !isset($ids[$slug])) {
                    throw new ToolCallException(\sprintf('Unknown column "%s". Use one of: %s.', \is_string($slug) ? $slug : get_debug_type($slug), $slugs));
                }
                $wanted[] = $ids[$slug];
            }
            if (\count($wanted) !== \count($ids) || \count(array_unique($wanted)) !== \count($wanted)) {
                throw new ToolCallException(\sprintf('Name each of these columns exactly once: %s.', $slugs));
            }

            ($this->reorderColumns)(new ReorderBoardColumnsCommand($project, implode(',', $wanted), implode(',', $ids)));
            $view = ($this->listColumns)(new ListBoardColumnsCommand($project));

            return ['columns' => $this->columns->forColumns($view->columns)];
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The columns could not be reordered. The error has been logged.', previous: $e);
        }
    }
}
