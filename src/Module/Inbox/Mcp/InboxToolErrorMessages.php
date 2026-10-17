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
use App\Module\Inbox\Service\RunSuggestions;
use App\Module\Inbox\Service\SessionPromptLimit;
use App\Module\Inbox\Service\SuggestedCommandCheck;
use Mcp\Exception\ToolCallException;

/**
 * Renders a handler's DomainErrors as the message an agent reads. An unmapped
 * key falls back to a generic message rather than leaking the key itself.
 */
final readonly class InboxToolErrorMessages
{
    public const string UNMAPPED = 'The request was rejected. The error has been logged.';

    public function __construct(
        private SessionPromptLimit $promptLimit,
    ) {
    }

    public function forAgent(DomainErrors $errors): ToolCallException
    {
        $lines = [];
        foreach ($errors->errors as $argument => $key) {
            $lines[] = \sprintf('%s: %s', $argument, $this->sentence($key));
        }

        return new ToolCallException(implode("\n", $lines), previous: $errors);
    }

    private function sentence(string $key): string
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
            AskInboxHandler::OPTION_TOO_LONG => \sprintf('An option must be at most %d characters.', InboxLimits::MAX_OPTION_LENGTH),
            AskInboxHandler::TOO_MANY_OPTIONS => \sprintf('A question takes at most %d options.', InboxLimits::MAX_OPTIONS),
            AskInboxHandler::TOO_MANY_LINKS => \sprintf('An item links at most %d cards and %d documents.', InboxLimits::MAX_LINKS, InboxLimits::MAX_LINKS),
            AskInboxHandler::TO_DO_WITH_ANSWER => 'A todo is marked done or declined, so it takes no options, multiple or freeText.',
            AskInboxHandler::QUESTION_WITHOUT_ANSWER => 'A question needs options, freeText, or both, so the owner can answer it.',
            AskInboxHandler::MULTIPLE_WITHOUT_OPTIONS => 'multiple needs at least two options.',
            AskInboxHandler::SUGGESTION_UNKNOWN_ACTION => \sprintf('A suggested action must be one of: %s. inbox_actions lists them.', implode(', ', RunSuggestions::ACTIONS)),
            AskInboxHandler::SUGGESTION_DUPLICATE => 'Suggest each action once.',
            AskInboxHandler::SUGGESTION_TWO_RECOMMENDED => 'Mark at most one suggested action as recommended.',
            AskInboxHandler::SUGGESTION_REASON_BLANK => 'A suggested action needs a reason the owner reads.',
            AskInboxHandler::SUGGESTION_REASON_TOO_LONG => \sprintf('A suggestion reason must be at most %d characters.', RunSuggestions::MAX_REASON_LENGTH),
            AskInboxHandler::SUGGESTION_CARD_NOT_LINKED => 'The cardId of a suggested action must be one of the cardIds of the same item.',
            AskInboxHandler::TOO_MANY_SUGGESTIONS => \sprintf('Suggest at most %d actions on one item.', RunSuggestions::MAX_SUGGESTIONS),
            AskInboxHandler::SUGGESTION_RUN_COMMAND_KIND => 'Only a todo can suggest run-command. A question and a review cannot.',
            AskInboxHandler::SUGGESTION_COMMAND_REQUIRED => 'A run-command suggestion needs a command with the name of the entry.',
            AskInboxHandler::SUGGESTION_COMMAND_UNEXPECTED => 'Only a run-command suggestion takes command and proposedEntry.',
            AskInboxHandler::SUGGESTION_BRANCH_INVALID => \sprintf('The branch of a command must be a git branch name of at most %d characters.', SuggestedCommandCheck::MAX_BRANCH_LENGTH),
            AskInboxHandler::SUGGESTION_PARAM_INVALID => 'The params of a command must name each param of the entry once, and each value must match the pattern of its param whole.',
            AskInboxHandler::SUGGESTION_ENTRY_INVALID => \sprintf('proposedEntry must be YAML of at most %d characters: a mapping with action: command, and params that each have a pattern.', RunSuggestions::MAX_PROPOSED_ENTRY_LENGTH),
            AskInboxHandler::SUGGESTION_PROMPT_UNEXPECTED => 'Only a start-session suggestion takes a prompt.',
            AskInboxHandler::SUGGESTION_PROMPT_TOO_LONG => \sprintf('A suggestion prompt must be at most %d characters on this instance.', $this->promptLimit->maxLength()),
            AskInboxHandler::SUGGESTION_PROMPT_BLANK => 'A suggestion prompt must not be blank. Leave it out, or write the instruction the session starts with.',
            InboxLinkResolver::CARD_UNKNOWN => 'Every card id must name a card of this project.',
            InboxLinkResolver::DOCUMENT_UNKNOWN => 'Every document id must name a document of this project.',
            InboxLinkResolver::PULL_REQUEST_UNKNOWN => 'The pull request id must name a pull request linked to a card in this project.',
            AskInboxHandler::REVIEW_TARGET_REQUIRED => 'A review requires exactly one reviewDocumentId or reviewPullRequestId.',
            AskInboxHandler::REVIEW_TARGET_WRONG_KIND => 'Only a review item can have a review target.',
            AskInboxHandler::REVIEW_WITH_ANSWER => 'A review takes Approve or Request changes, so it accepts no options, multiple or freeText.',
            InboxSessionAsks::SESSION_ASK_ELSEWHERE => 'This session already has an open ask in another project. Ask there, or wait until that ask closes.',
            InboxSessionAsks::BRIDGE_MISMATCH => 'This session\'s open ask already names another bridge. Pass the same bridgeId, or none.',
            InboxSessionAsks::CONTEXT_TOO_LONG => \sprintf('The context of an ask must be at most %d characters, counting the context earlier calls added.', InboxLimits::MAX_CONTEXT_LENGTH),
            InboxItemCloser::ITEM_NOT_OPEN => 'The item is already closed. Read its answer with inbox_get.',
            WithdrawInboxItemHandler::REASON_BLANK => 'Pass the reason you no longer need the item.',
            WithdrawInboxItemHandler::REASON_TOO_LONG => \sprintf('A withdraw reason must be at most %d characters.', InboxLimits::MAX_WITHDRAW_REASON_LENGTH),
            WithdrawInboxItemHandler::WAIT_NOT_WITHDRAWABLE => 'Loupe opened this item for a card that waits for a person, so no agent can withdraw it. It closes when the card no longer waits.',
            WithdrawInboxItemHandler::NOTICE_NOT_WITHDRAWABLE => 'Loupe opened this notice for a fact about the project, so no agent can withdraw it. It closes when the fact ends.',
            SearchInboxHandler::QUERY_BLANK => 'Pass a query to search for. To read the inbox page by page instead, call inbox_list.',
            default => self::UNMAPPED,
        };
    }
}
