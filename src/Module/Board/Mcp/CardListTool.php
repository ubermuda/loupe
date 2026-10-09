<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Module\Board\Command\ListBoardColumnsCommand;
use App\Module\Board\Command\ListBoardColumnsHandler;
use App\Module\Board\Command\ListCardsCommand;
use App\Module\Board\Command\ListCardsHandler;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Reads the project's board.
 *
 * @phpstan-import-type CardSummary from CardPayload
 * @phpstan-import-type CardListSummary from CardPayload
 * @phpstan-import-type BoardColumnSummary from BoardColumnPayload
 */
#[McpTool(name: self::NAME, description: 'List the cards on the project board. Filter by status, the slug of a column on this board. Each board has its own columns. The response lists them in columns, and board_columns lists them alone. You can also filter by type, a key that board_columns lists, by reporter (human, agent, reviewer), who raised the card, or by parentCardId, which reads the children of one card. Pass paused true to read only the cards that a workflow pause holds, or false to read only the cards with none; total and paging count the filtered set. A card is never raised by system, which names the app acting on an approval. An open column reads in board order, by position. A terminal column is where finished work goes, and it reads newest completion first, with no time window on it. Each row is a summary: cardId, number, title, type, status, reporter, parentCardId, updatedAt and state. state is null, or holds kind (stuck, needs-you, working or waiting), code and since: what the card needs now on the board. Each entry of columns has slug, label, terminal and default. Pass full to get the body, the pull request and document links, the feedback items (siteReviewComments), the linked cards (relatedCards), the parent, the lane setting, the children and the progress of a card whose type may have children, and the active pause as well, which is far larger. pause is null, or holds pauseId, kind (rule, retries, work-limit, work-timeout or work-stopped), reason, ruleId and since. With full, each pull request also carries its stored state, and state also holds reason and others, as card_get describes. Paginated: pass page to walk further, and keep going while hasMore is true. Every card carries a number, the short label that counts from 1 inside this project. Use it to name a card to a person, and use the cardId or the number to read or change it.')]
final readonly class CardListTool
{
    public const string NAME = 'card_list';

    public function __construct(
        private BoardSubjectResolver $subjects,
        private ListCardsHandler $listCards,
        private CardPayload $payload,
        private ListBoardColumnsHandler $listColumns,
        private BoardColumnPayload $columns,
    ) {
    }

    /**
     * The list is wrapped in a `cards` object key because the MCP spec requires
     * a tool result's `structuredContent` to be a JSON object, not a bare array.
     *
     * @param string|null $status       only cards in the column with this slug; board_columns lists the slugs of this board
     * @param string|null $type         only cards of this type key; board_columns lists the types of this project
     * @param string|null $reporter     only cards raised by this reporter: human, agent or reviewer
     * @param int         $page         the 1-based page to read
     * @param int         $perPage      how many cards to return per page
     * @param bool        $full         return the whole card, body and links included, rather than the summary
     * @param string|null $parentCardId only the children of this card of the project
     * @param bool|null   $paused       true for only the cards with an active pause, false for only those with none
     *
     * @return ($full is true ? array{cards: list<CardSummary>, columns: list<BoardColumnSummary>, page: int, perPage: int, total: int, hasMore: bool} : array{cards: list<CardListSummary>, columns: list<BoardColumnSummary>, page: int, perPage: int, total: int, hasMore: bool})
     */
    public function __invoke(?string $status = null, ?string $type = null, ?string $reporter = null, int $page = 1, int $perPage = ListCardsHandler::DEFAULT_PER_PAGE, bool $full = false, ?string $parentCardId = null, ?bool $paused = null): array
    {
        try {
            $project = $this->subjects->requireProject();
            $columns = ($this->listColumns)(new ListBoardColumnsCommand($project))->columns;
            $view = ($this->listCards)(new ListCardsCommand(
                project: $project,
                column: $this->subjects->optionalColumnAmong($columns, $status),
                type: $this->subjects->optionalType($project, $type),
                reporter: $this->subjects->optionalReporter($reporter),
                page: $page,
                perPage: $perPage,
                parent: null === $parentCardId ? null : $this->subjects->requireCard($parentCardId, McpBoundProjectVoter::CARD_READ),
                paused: $paused,
            ));

            $meta = ['columns' => $this->columns->forColumns($columns), 'page' => $view->page, 'perPage' => $view->perPage, 'total' => $view->total, 'hasMore' => $view->hasMore];

            if ($full) {
                return ['cards' => $this->payload->forCards($view->cards), ...$meta];
            }

            return ['cards' => $this->payload->forCardList($view->cards), ...$meta];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The board could not be read. The error has been logged.', previous: $e);
        }
    }
}
