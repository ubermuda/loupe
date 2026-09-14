<?php

declare(strict_types=1);

namespace App\Module\Inbox\Mcp;

use App\Exception\DomainErrors;
use App\Module\Inbox\Command\AskInboxHandler;
use App\Module\Inbox\Command\SearchInboxHandler;
use App\Module\Inbox\Command\WithdrawInboxItemHandler;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\InboxLimits;
use App\Module\Inbox\Service\InboxItemCloser;
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
            AskInboxHandler::TOO_MANY_ITEMS => \sprintf('Pass at most %d items in one call.', InboxLimits::MAX_ITEMS_PER_CALL),
            AskInboxHandler::TITLE_BLANK => 'An item title must not be blank.',
            AskInboxHandler::TITLE_TOO_LONG => \sprintf('An item title must be at most %d characters.', InboxItem::MAX_TITLE_LENGTH),
            AskInboxHandler::TITLE_MULTILINE => 'An item title must be one line. Put the detail in body.',
            AskInboxHandler::BODY_TOO_LONG => \sprintf('An item body must be at most %d characters.', InboxLimits::MAX_BODY_LENGTH),
            AskInboxHandler::OPTION_BLANK => 'An option must not be blank.',
            AskInboxHandler::OPTION_DUPLICATE => 'Each option must differ from the others.',
            AskInboxHandler::TOO_MANY_OPTIONS => \sprintf('A question takes at most %d options.', InboxLimits::MAX_OPTIONS),
            AskInboxHandler::TOO_MANY_LINKS => \sprintf('An item links at most %d cards and %d documents.', InboxLimits::MAX_LINKS, InboxLimits::MAX_LINKS),
            AskInboxHandler::TO_DO_WITH_ANSWER => 'A todo is marked done or declined, so it takes no options, multiple or freeText.',
            AskInboxHandler::QUESTION_WITHOUT_ANSWER => 'A question needs options, freeText, or both, so the owner can answer it.',
            AskInboxHandler::MULTIPLE_WITHOUT_OPTIONS => 'multiple needs at least two options.',
            InboxLinkResolver::CARD_UNKNOWN => 'Every card id must name a card of this project.',
            InboxLinkResolver::DOCUMENT_UNKNOWN => 'Every document id must name a document of this project.',
            InboxSessionAsks::SESSION_ASK_ELSEWHERE => 'This session already has an open ask in another project. Ask there, or wait until that ask closes.',
            InboxSessionAsks::BRIDGE_MISMATCH => 'This session\'s open ask already names another bridge. Pass the same bridgeId, or none.',
            InboxSessionAsks::CONTEXT_TOO_LONG => \sprintf('The context of an ask must be at most %d characters, counting the context earlier calls added.', InboxLimits::MAX_CONTEXT_LENGTH),
            InboxItemCloser::ITEM_NOT_OPEN => 'The item is already closed. Read its answer with inbox_get.',
            WithdrawInboxItemHandler::REASON_BLANK => 'Pass the reason you no longer need the item.',
            WithdrawInboxItemHandler::REASON_TOO_LONG => \sprintf('A withdraw reason must be at most %d characters.', InboxLimits::MAX_WITHDRAW_REASON_LENGTH),
            SearchInboxHandler::QUERY_BLANK => 'Pass a query to search for. To read the inbox page by page instead, call inbox_list.',
            default => self::UNMAPPED,
        };
    }
}
