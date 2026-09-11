<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Exception\DomainErrors;
use App\Mcp\FlagGatedToolInterface;
use App\Module\Board\Command\SearchBoardCommand;
use App\Module\Board\Command\SearchBoardHandler;
use App\Module\Board\Install\BoardInstallFlags;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Full-text search over the project's board.
 *
 * @phpstan-import-type CardListSummary from CardPayload
 */
#[McpTool(name: self::NAME, description: 'Search the project board by words. Use it to answer "is there already a card about this?" before you write a new one. It reads both the title and the body of every card, in every column, done included: a topic is often named only in a body, and "yes, and it is already done" is a true answer. Matching is by word rather than by substring, and words are stemmed, so "paging" finds "pages". Quote a phrase to require it, prefix a word with - to exclude it, and write or between words for either. Rows come back best match first, each a summary: cardId, number, title, type, priority, status, origin and updatedAt. Call card_get with a cardId to read a body. Paginated: pass page to walk further, and keep going while hasMore is true.')]
final readonly class CardSearchTool implements FlagGatedToolInterface
{
    public const string NAME = 'card_search';

    public function __construct(
        private BoardFlagGate $gate,
        private BoardSubjectResolver $subjects,
        private SearchBoardHandler $searchBoard,
        private CardPayload $payload,
        private BoardToolErrorMessages $errorMessages,
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
     * The list is wrapped in a `cards` object key because the MCP spec requires
     * a tool result's `structuredContent` to be a JSON object, not a bare array.
     *
     * Unlike card_list, the page is cut in SQL: one ranked query orders the
     * whole result, so a LIMIT expresses it.
     *
     * @param string $query   the words to search for
     * @param int    $page    the 1-based page to read
     * @param int    $perPage how many cards to return per page
     *
     * @return array{cards: list<CardListSummary>, page: int, perPage: int, total: int, hasMore: bool}
     */
    public function __invoke(string $query, int $page = 1, int $perPage = SearchBoardHandler::DEFAULT_PER_PAGE): array
    {
        $this->gate->requireEnabled();

        try {
            $view = ($this->searchBoard)(new SearchBoardCommand(
                project: $this->subjects->requireProject(),
                query: $query,
                page: $page,
                perPage: $perPage,
            ));

            return [
                'cards' => $this->payload->forCardList($view->cards),
                'page' => $view->page,
                'perPage' => $view->perPage,
                'total' => $view->total,
                'hasMore' => $view->hasMore,
            ];
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The board could not be searched. The error has been logged.', previous: $e);
        }
    }
}
