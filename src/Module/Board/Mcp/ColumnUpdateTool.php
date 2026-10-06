<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Exception\DomainErrors;
use App\Module\Board\Command\ConfigureBoardColumnCommand;
use App\Module\Board\Command\ConfigureBoardColumnHandler;
use App\Module\Board\Command\ListBoardColumnsCommand;
use App\Module\Board\Command\ListBoardColumnsHandler;
use App\Module\Board\Entity\CardReporter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The tool has no form that showed the column, so the expected values are the
 * column as this call reads it.
 *
 * @phpstan-import-type BoardColumnSummary from BoardColumnPayload
 */
#[McpTool(name: self::NAME, description: 'Rename a column of the project board, or set whether it is terminal. Name the column by its slug, from board_columns. A field you leave out keeps the value it has. A rename changes the slug, because the slug comes from the label. Anything outside the app that uses the old slug, such as a bridge rule or a script, then stops working. A terminal column is where finished work goes: a card in it gets a completion time, and a card in a column that stops being terminal loses it. A board keeps at least one terminal column. Nobody can change Backlog. The result names the slug of the column after the change and lists every column of the board.')]
final readonly class ColumnUpdateTool
{
    public const string NAME = 'column_update';

    public function __construct(
        private BoardSubjectResolver $subjects,
        private ConfigureBoardColumnHandler $configureColumn,
        private ListBoardColumnsHandler $listColumns,
        private BoardColumnPayload $columns,
        private BoardToolErrorMessages $errorMessages,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param string      $slug     the slug of the column to change, from board_columns
     * @param string|null $label    a new name for the column; the slug changes with it
     * @param bool|null   $terminal whether finished work goes in this column
     *
     * @return array{column: string, columns: list<BoardColumnSummary>}
     */
    public function __invoke(string $slug, ?string $label = null, ?bool $terminal = null): array
    {
        try {
            $project = $this->subjects->requireProject();
            $column = $this->subjects->requireColumn($project, $slug, 'column');

            // The handler keeps a seeded label's key only when it gets the translated label back.
            ($this->configureColumn)(new ConfigureBoardColumnCommand(
                column: $column,
                actor: CardReporter::Agent,
                label: $label ?? $this->translator->trans($column->label),
                terminal: $terminal ?? $column->terminal,
                expectedLabel: $column->label,
                expectedTerminal: $column->terminal,
            ));
            $view = ($this->listColumns)(new ListBoardColumnsCommand($project));

            return ['column' => $column->slug, 'columns' => $this->columns->forColumns($view->columns)];
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e, ['column' => 'slug']);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The column could not be changed. The error has been logged.', previous: $e);
        }
    }
}
