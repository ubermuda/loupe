<?php

declare(strict_types=1);

namespace App\Module\Inbox\Mcp;

use App\Exception\DomainErrors;
use App\Mcp\FlagGatedToolInterface;
use App\Module\Inbox\Command\AskInboxCommand;
use App\Module\Inbox\Command\AskInboxHandler;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\InboxLimits;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Service\RunSuggestions;
use App\Module\Inbox\Service\SessionPromptLimit;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * Hands questions, reviews and to-dos to the project owner.
 *
 * @phpstan-import-type InboxAskSummary from InboxItemPayload
 */
#[McpTool(name: self::NAME, description: 'Hand questions, reviews and to-dos to the project owner, who answers them in the Loupe inbox. Call inbox_search first: when an open item already asks the same thing, add it to your ask with inbox_join instead. Pass your sessionId, the id of your agent session: the first set value of $LOUPE_SESSION_ID, $CLAUDE_CODE_SESSION_ID and $CODEX_THREAD_ID. Pass bridgeId when the bridge started you, so it can resume your session once the owner answers. Pass context, one or two sentences the owner reads above the items. Each item has a kind, question, todo or review, and a title. A review requires exactly one reviewDocumentId or reviewPullRequestId. Read pullRequestId from card_get. The owner approves or requests changes in Loupe; no code-host review is sent. A question takes options, freeText, or both; set multiple to let the owner pick several options. A todo, such as "publish the release notes", takes no options, and the owner marks it done or declines it. blocking says whether you wait for the item: a question blocks unless you pass false, and a todo or review does not block unless you pass true. cardIds and documentIds link the item to cards and documents of this project. suggestedActions, at most three, tells the owner which built-in actions you suggest next to the answer; call inbox_actions for the list and for the actions that fit a card now. Loupe shows a suggestion only while the action fits the item. A todo can suggest run-command: set command to the entry name, an optional branch (default main) and params, and set proposedEntry to the YAML of the entry when your bridge may not have it. Loupe checks the params against the pattern of the entry on your bridge, else against proposedEntry. A start-session suggestion can set prompt, the instruction the session starts with; the owner reads and may edit it before the session starts. The items go to your session\'s open ask, or to a new ask when you have none. A new ask with no blocking item closes at once, and its items stay in the inbox. The response carries the askId and each item with its itemId and its number, the short label that counts from 1 inside this project. After a blocking ask, end your turn.')]
final readonly class InboxAskTool implements FlagGatedToolInterface
{
    public const string NAME = 'inbox_ask';

    public function __construct(
        private InboxFlagGate $gate,
        private InboxSubjectResolver $subjects,
        private AskInboxHandler $ask,
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
     * @param string       $sessionId the id of your agent session, the first set value of $LOUPE_SESSION_ID, $CLAUDE_CODE_SESSION_ID and $CODEX_THREAD_ID
     * @param array<mixed> $items     the questions, reviews and to-dos to hand over
     * @param string|null  $bridgeId  the id of the bridge that started you, when one did
     * @param string|null  $context   a short note the owner reads above the items
     *
     * @return InboxAskSummary
     */
    public function __invoke(
        string $sessionId,
        #[Schema(type: 'array', items: [
            'type' => 'object',
            'properties' => [
                'kind' => ['type' => 'string', 'enum' => ['question', 'todo', 'review']],
                'title' => ['type' => 'string', 'maxLength' => InboxItem::MAX_TITLE_LENGTH, 'description' => 'one line'],
                'body' => ['type' => 'string', 'maxLength' => InboxLimits::MAX_BODY_LENGTH, 'description' => 'the detail, in Markdown'],
                'options' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => InboxLimits::MAX_OPTION_LENGTH], 'maxItems' => InboxLimits::MAX_OPTIONS, 'description' => 'the answers the owner picks from, each different; a question only'],
                'multiple' => ['type' => 'boolean', 'description' => 'the owner may pick several options'],
                'freeText' => ['type' => 'boolean', 'description' => 'the owner may write an answer; a question only'],
                'blocking' => ['type' => 'boolean', 'description' => 'you wait for this item; defaults to true for a question and false for a todo or review'],
                'cardIds' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => InboxLimits::MAX_LINKS, 'description' => 'ids of cards of this project'],
                'documentIds' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => InboxLimits::MAX_LINKS, 'description' => 'ids of documents of this project'],
                'reviewDocumentId' => ['type' => 'string', 'description' => 'the document to review; a review requires exactly one reviewDocumentId or reviewPullRequestId'],
                'reviewPullRequestId' => ['type' => 'string', 'description' => 'the pullRequestId from card_get to review; records a Loupe response without submitting a code-host review'],
                'suggestedActions' => ['type' => 'array', 'maxItems' => RunSuggestions::MAX_SUGGESTIONS, 'description' => 'actions you suggest the owner takes with this item, each once; call inbox_actions for the list', 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => ['type' => 'string', 'enum' => RunSuggestions::ACTIONS],
                        'reason' => ['type' => 'string', 'maxLength' => RunSuggestions::MAX_REASON_LENGTH, 'description' => 'one line the owner reads next to the action'],
                        'recommended' => ['type' => 'boolean', 'description' => 'the action Loupe offers first, when it fits; mark at most one'],
                        'cardId' => ['type' => 'string', 'description' => 'the card the action is about; one of the cardIds of this item'],
                        'command' => ['type' => 'object', 'description' => 'for run-command on a todo only: the command entry of the rule file of your bridge that runs once the owner confirms', 'properties' => [
                            'entry' => ['type' => 'string', 'description' => 'the name of the command entry'],
                            'branch' => ['type' => 'string', 'description' => 'the branch to run it on; defaults to main'],
                            'params' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'description' => 'the value of each param of the entry; each value must match the pattern of its param whole'],
                        ], 'required' => ['entry']],
                        'prompt' => ['type' => 'string', 'description' => 'for start-session only: the instruction the session starts with; the owner reads and may edit it first. At most '.SessionPromptLimit::DEFAULT.' characters by default; an admin can set a lower or higher limit'],
                        'proposedEntry' => ['type' => 'string', 'maxLength' => RunSuggestions::MAX_PROPOSED_ENTRY_LENGTH, 'description' => 'for run-command: the YAML of one command entry, without its name, for a bridge that lacks the entry. Keys: action (command), description, params (each name with a pattern), run, timeout'],
                    ],
                    'required' => ['action', 'reason'],
                ]],
            ],
            'required' => ['kind', 'title'],
        ], minItems: 1, maxItems: InboxLimits::MAX_ITEMS_PER_CALL)]
        array $items,
        ?string $bridgeId = null,
        #[Schema(maxLength: InboxLimits::MAX_CONTEXT_LENGTH)]
        ?string $context = null,
    ): array {
        $this->gate->requireEnabled();

        try {
            $view = ($this->ask)(new AskInboxCommand(
                project: $this->subjects->requireProject(),
                sessionId: $this->subjects->requireUuid($sessionId, 'session ID'),
                items: $this->subjects->requireAskItems($items),
                bridgeId: $this->subjects->optionalUuid($bridgeId, 'bridge ID'),
                context: $context,
            ));

            return $this->payload->forAsk($view->ask, $view->items, $view->extended);
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The items could not be handed over. The error has been logged.', previous: $e);
        }
    }
}
