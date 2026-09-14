<?php

declare(strict_types=1);

namespace App\Module\Inbox\Mcp;

use App\Exception\DomainErrors;
use App\Mcp\FlagGatedToolInterface;
use App\Module\Inbox\Command\SearchInboxCommand;
use App\Module\Inbox\Command\SearchInboxHandler;
use App\Module\Inbox\Install\InboxInstallFlags;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Full-text search over the project's inbox.
 *
 * @phpstan-import-type InboxItemListSummary from InboxItemPayload
 */
#[McpTool(name: self::NAME, description: 'Search the project inbox by words. Use it before inbox_ask, to answer "has someone already asked the owner this?". It reads the title and the body of every item, closed items included, because an answered question records a decision. Matching is by word rather than by substring, and words are stemmed, so "paging" finds "pages". Quote a phrase to require it, prefix a word with - to exclude it, and write or between words for either. Rows come back best match first, each a summary: itemId, number, kind, title, state, blocking, createdAt, updatedAt and closedAt. Call inbox_get with an itemId to read the body and the answer. Paginated: pass page to walk further, and keep going while hasMore is true.')]
final readonly class InboxSearchTool implements FlagGatedToolInterface
{
    public const string NAME = 'inbox_search';

    public function __construct(
        private InboxFlagGate $gate,
        private InboxSubjectResolver $subjects,
        private SearchInboxHandler $search,
        private InboxItemPayload $payload,
        private InboxToolErrorMessages $errorMessages,
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
        return InboxInstallFlags::FLAG_INBOX_ENABLED;
    }

    /**
     * The list is wrapped in an `items` key because a tool result's
     * `structuredContent` must be a JSON object.
     *
     * @param string $query   the words to search for
     * @param int    $page    the 1-based page to read
     * @param int    $perPage how many items to return per page
     *
     * @return array{items: list<InboxItemListSummary>, page: int, perPage: int, total: int, hasMore: bool}
     */
    public function __invoke(string $query, int $page = 1, int $perPage = SearchInboxHandler::DEFAULT_PER_PAGE): array
    {
        $this->gate->requireEnabled();

        try {
            $view = ($this->search)(new SearchInboxCommand(
                project: $this->subjects->requireProject(),
                query: $query,
                page: $page,
                perPage: $perPage,
            ));

            return [
                'items' => $this->payload->forList($view->items),
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
            throw new ToolCallException('The inbox could not be searched. The error has been logged.', previous: $e);
        }
    }
}
