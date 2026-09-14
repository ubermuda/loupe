<?php

declare(strict_types=1);

namespace App\Module\Inbox\Mcp;

use App\Exception\DomainErrors;
use App\Mcp\FlagGatedToolInterface;
use App\Module\Inbox\Command\WithdrawInboxItemCommand;
use App\Module\Inbox\Command\WithdrawInboxItemHandler;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Takes back an item the agent no longer needs.
 *
 * @phpstan-import-type InboxItemListSummary from InboxItemPayload
 */
#[McpTool(name: self::NAME, description: 'Withdraw an open inbox item you no longer need answered, for example because the pull request of a review to-do merged. Pass the reason: the owner reads it in the item\'s closeNote. The item closes with the state withdrawn. Only an open item can be withdrawn, and a closed item is refused. The response is the item\'s summary.')]
final readonly class InboxWithdrawTool implements FlagGatedToolInterface
{
    public const string NAME = 'inbox_withdraw';

    public function __construct(
        private InboxFlagGate $gate,
        private InboxSubjectResolver $subjects,
        private WithdrawInboxItemHandler $withdraw,
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
     * @param string $itemId the id of the open item to withdraw
     * @param string $reason why you no longer need it, which the owner reads
     *
     * @return InboxItemListSummary
     */
    public function __invoke(string $itemId, string $reason): array
    {
        $this->gate->requireEnabled();

        try {
            $item = ($this->withdraw)(new WithdrawInboxItemCommand(
                item: $this->subjects->requireItem($itemId, McpBoundProjectVoter::INBOX_ITEM_WRITE),
                reason: $reason,
            ));

            return $this->payload->forListItem($item);
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The item could not be withdrawn. The error has been logged.', previous: $e);
        }
    }
}
