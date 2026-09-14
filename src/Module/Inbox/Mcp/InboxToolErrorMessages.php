<?php

declare(strict_types=1);

namespace App\Module\Inbox\Mcp;

use App\Exception\DomainErrors;
use App\Module\Inbox\Command\AskInboxHandler;
use App\Module\Inbox\Command\JoinInboxAskHandler;
use App\Module\Inbox\Command\SearchInboxHandler;
use App\Module\Inbox\Command\WithdrawInboxItemHandler;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Service\InboxLinkResolver;
use App\Module\Inbox\Service\InboxSessionAsks;
use Mcp\Exception\ToolCallException;

/**
 * Renders a handler's DomainErrors as the message an agent reads. An unmapped
 * key falls back to a generic message rather than leaking the key itself.
 */
final readonly class InboxToolErrorMessages
{
    public const string UNMAPPED = 'The request was rejected. The error has been logged.';

    public function forAgent(DomainErrors $errors): ToolCallException
    {
        $lines = [];
        foreach ($errors->errors as $argument => $key) {
            $lines[] = \sprintf('%s: %s', $argument, self::sentence($key));
        }

        return new ToolCallException(implode("\n", $lines), previous: $errors);
    }

    private static function sentence(string $key): string
    {
        return match ($key) {
            AskInboxHandler::ITEMS_EMPTY => 'Pass at least one item.',
            AskInboxHandler::TITLE_BLANK => 'An item title must not be blank.',
            AskInboxHandler::TITLE_TOO_LONG => \sprintf('An item title must be at most %d characters.', InboxItem::MAX_TITLE_LENGTH),
            AskInboxHandler::OPTION_BLANK => 'An option must not be blank.',
            AskInboxHandler::TO_DO_WITH_ANSWER => 'A todo is marked done or declined, so it takes no options, multiple or freeText.',
            AskInboxHandler::QUESTION_WITHOUT_ANSWER => 'A question needs options, freeText, or both, so the owner can answer it.',
            AskInboxHandler::MULTIPLE_WITHOUT_OPTIONS => 'multiple needs at least two options.',
            InboxLinkResolver::CARD_UNKNOWN => 'Every card id must name a card of this project.',
            InboxLinkResolver::DOCUMENT_UNKNOWN => 'Every document id must name a document of this project.',
            InboxSessionAsks::SESSION_ASK_ELSEWHERE => 'This session already has an open ask in another project. Ask there, or wait until that ask closes.',
            JoinInboxAskHandler::ITEM_NOT_OPEN => 'The item is already closed. Read its answer with inbox_get.',
            WithdrawInboxItemHandler::REASON_BLANK => 'Pass the reason you no longer need the item.',
            SearchInboxHandler::QUERY_BLANK => 'Pass a query to search for. To read the inbox page by page instead, call inbox_list.',
            default => self::UNMAPPED,
        };
    }
}
