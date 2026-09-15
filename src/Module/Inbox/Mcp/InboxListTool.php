<?php

declare(strict_types=1);

namespace App\Module\Inbox\Mcp;

use App\Mcp\FlagGatedToolInterface;
use App\Module\Inbox\Command\ListInboxItemsCommand;
use App\Module\Inbox\Command\ListInboxItemsHandler;
use App\Module\Inbox\Install\InboxInstallFlags;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Reads the project's inbox page by page.
 *
 * @phpstan-import-type InboxItemListSummary from InboxItemPayload
 * @phpstan-import-type InboxItemListRow from InboxItemPayload
 */
#[McpTool(name: self::NAME, description: 'List the items in the project inbox, newest first. Filter by state (open, answered, done, declined, withdrawn, obsolete), by askId to read the items of one ask, by sessionId to read the items of every ask a session made, by cardId or by documentId. An id that names nothing in this project matches nothing. Pass your own session id as readerSessionId, never as sessionId, to record that you read the answers of your closed asks, so a bridge can skip resuming you for answers you already read. Each row holds itemId, number, kind, title, state, blocking, createdAt, updatedAt and closedAt. With readerSessionId, each row also holds the response: options, selectedOptions (the indexes of the options the owner picked), answerText and closeNote. Call inbox_get with an itemId to read the body, the answer and the links. Paginated: pass page to walk further, and keep going while hasMore is true.')]
final readonly class InboxListTool implements FlagGatedToolInterface
{
    public const string NAME = 'inbox_list';

    public function __construct(
        private InboxFlagGate $gate,
        private InboxSubjectResolver $subjects,
        private ListInboxItemsHandler $list,
        private InboxItemPayload $payload,
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
     * @param string|null $state           only items in this state: open, answered, done, declined, withdrawn or obsolete
     * @param string|null $askId           only the items of this ask
     * @param string|null $sessionId       only the items of the asks this session made
     * @param string|null $cardId          only the items linked to this card
     * @param string|null $documentId      only the items linked to this document
     * @param int         $page            the 1-based page to read
     * @param int         $perPage         how many items to return per page
     * @param string|null $readerSessionId your own session id, which records that you read the items of your closed asks on this page; it filters nothing
     *
     * @return array{items: list<InboxItemListSummary>|list<InboxItemListRow>, page: int, perPage: int, total: int, hasMore: bool}
     */
    public function __invoke(?string $state = null, ?string $askId = null, ?string $sessionId = null, ?string $cardId = null, ?string $documentId = null, int $page = 1, int $perPage = ListInboxItemsHandler::DEFAULT_PER_PAGE, ?string $readerSessionId = null): array
    {
        $this->gate->requireEnabled();

        try {
            $view = ($this->list)(new ListInboxItemsCommand(
                project: $this->subjects->requireProject(),
                state: $this->subjects->optionalState($state),
                askId: $this->subjects->optionalUuid($askId, 'ask ID'),
                sessionId: $this->subjects->optionalUuid($sessionId, 'session ID'),
                cardId: $this->subjects->optionalUuid($cardId, 'card ID'),
                documentId: $this->subjects->optionalUuid($documentId, 'document ID'),
                page: $page,
                perPage: $perPage,
                readerSessionId: $this->subjects->optionalUuid($readerSessionId, 'reader session ID'),
            ));

            return [
                'items' => null === $readerSessionId ? $this->payload->forList($view->items) : $this->payload->forListRows($view->items),
                'page' => $view->page,
                'perPage' => $view->perPage,
                'total' => $view->total,
                'hasMore' => $view->hasMore,
            ];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The inbox could not be read. The error has been logged.', previous: $e);
        }
    }
}
