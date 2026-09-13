<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Mcp\FlagGatedToolInterface;
use App\Module\Board\Command\ListBoardColumnsCommand;
use App\Module\Board\Command\ListBoardColumnsHandler;
use App\Module\Board\Install\BoardInstallFlags;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Reads the columns of the project's board. Nothing writes a column through MCP,
 * so an agent cannot rename a slug that something outside the app depends on.
 *
 * @phpstan-import-type BoardColumnSummary from BoardColumnPayload
 */
#[McpTool(name: self::NAME, description: 'List the columns of the project board, in board order. Each board has its own columns, so read them here before you pass a status to card_create, card_update or card_list. Each column has a slug, a label, a terminal flag and a default flag. The slug is the value you pass as status. The label is the name a person sees on the board. A terminal column is where finished work goes: a card that enters one gets a completion time. The default column is where a new card lands when you pass no status. Renaming a column changes its slug.')]
final readonly class BoardColumnsTool implements FlagGatedToolInterface
{
    public const string NAME = 'board_columns';

    public function __construct(
        private BoardFlagGate $gate,
        private BoardSubjectResolver $subjects,
        private ListBoardColumnsHandler $listColumns,
        private BoardColumnPayload $columns,
    ) {
    }

    #[\Override]
    public function gatedToolName(): string
    {
        return self::NAME;
    }

    #[\Override]
    public function requiredFlag(): string
    {
        return BoardInstallFlags::FLAG_BOARD_ENABLED;
    }

    /**
     * The list is wrapped in a `columns` object key because the MCP spec requires
     * a tool result's `structuredContent` to be a JSON object, not a bare array.
     *
     * @return array{columns: list<BoardColumnSummary>}
     */
    public function __invoke(): array
    {
        $this->gate->requireEnabled();

        try {
            $view = ($this->listColumns)(new ListBoardColumnsCommand($this->subjects->requireProject()));

            return ['columns' => $this->columns->forColumns($view->columns)];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The board columns could not be read. The error has been logged.', previous: $e);
        }
    }
}
