<?php

declare(strict_types=1);

namespace App\Module\Inbox\Mcp;

use App\Exception\DomainErrors;
use App\Mcp\FlagGatedToolInterface;
use App\Module\Inbox\Command\JoinInboxAskCommand;
use App\Module\Inbox\Command\JoinInboxAskHandler;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Adds an existing open item to the session's ask.
 *
 * @phpstan-import-type InboxAskSummary from InboxItemPayload
 */
#[McpTool(name: self::NAME, description: 'Add an open item that is already in the inbox to your own ask, so you wait for the same answer instead of asking again. Find the item with inbox_search or inbox_list. Pass your sessionId, from $CLAUDE_CODE_SESSION_ID, and bridgeId when the bridge started you. The item goes to your session\'s open ask, or to a new ask when you have none. One answer then counts toward every ask that holds the item. A new ask whose only item does not block closes at once. A closed item is refused: read its answer with inbox_get. The response carries the askId and the item.')]
final readonly class InboxJoinTool implements FlagGatedToolInterface
{
    public const string NAME = 'inbox_join';

    public function __construct(
        private InboxFlagGate $gate,
        private InboxSubjectResolver $subjects,
        private JoinInboxAskHandler $join,
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
     * @param string      $itemId    the id of the open item to wait for, from inbox_search or inbox_list
     * @param string      $sessionId the id of your Claude Code session, from $CLAUDE_CODE_SESSION_ID
     * @param string|null $bridgeId  the id of the bridge that started you, when one did
     *
     * @return InboxAskSummary
     */
    public function __invoke(string $itemId, string $sessionId, ?string $bridgeId = null): array
    {
        $this->gate->requireEnabled();

        try {
            $view = ($this->join)(new JoinInboxAskCommand(
                item: $this->subjects->requireItem($itemId, McpBoundProjectVoter::INBOX_ITEM_WRITE),
                sessionId: $this->subjects->requireUuid($sessionId, 'session ID'),
                bridgeId: $this->subjects->optionalUuid($bridgeId, 'bridge ID'),
            ));

            return $this->payload->forAsk($view->ask, [$view->item], $view->extended);
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The item could not be added to your ask. The error has been logged.', previous: $e);
        }
    }
}
