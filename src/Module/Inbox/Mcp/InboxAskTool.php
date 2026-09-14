<?php

declare(strict_types=1);

namespace App\Module\Inbox\Mcp;

use App\Exception\DomainErrors;
use App\Mcp\FlagGatedToolInterface;
use App\Module\Inbox\Command\AskInboxCommand;
use App\Module\Inbox\Command\AskInboxHandler;
use App\Module\Inbox\Install\InboxInstallFlags;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * Hands questions and to-dos to the project owner.
 *
 * @phpstan-import-type InboxAskSummary from InboxItemPayload
 */
#[McpTool(name: self::NAME, description: 'Hand questions and to-dos to the project owner, who answers them in the Loupe inbox. Call inbox_search first: when an open item already asks the same thing, add it to your ask with inbox_join instead. Pass your sessionId, the id of your own Claude Code session, which the Bash tool reads from $CLAUDE_CODE_SESSION_ID. Pass bridgeId when the bridge started you, so it can resume your session once the owner answers. Pass context, one or two sentences the owner reads above the items. Each item has a kind, question or todo, and a title. A question takes options, freeText, or both; set multiple to let the owner pick several options. A todo, such as "review pull request 482", takes no options, and the owner marks it done or declines it. blocking says whether you wait for the item: a question blocks unless you pass false, and a todo does not block unless you pass true. cardIds and documentIds link the item to cards and documents of this project. The items go to your session\'s open ask, or to a new ask when you have none. A new ask with no blocking item closes at once, and its items stay in the inbox. The response carries the askId and each item with its itemId and its number, the short label that counts from 1 inside this project. After a blocking ask, end your turn.')]
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
     * @param string       $sessionId the id of your Claude Code session, from $CLAUDE_CODE_SESSION_ID
     * @param array<mixed> $items     the questions and to-dos to hand over
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
                'kind' => ['type' => 'string', 'enum' => ['question', 'todo']],
                'title' => ['type' => 'string', 'description' => 'one line, at most 255 characters'],
                'body' => ['type' => 'string', 'description' => 'the detail, in Markdown'],
                'options' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'the answers the owner picks from; a question only'],
                'multiple' => ['type' => 'boolean', 'description' => 'the owner may pick several options'],
                'freeText' => ['type' => 'boolean', 'description' => 'the owner may write an answer; a question only'],
                'blocking' => ['type' => 'boolean', 'description' => 'you wait for this item; defaults to true for a question and false for a todo'],
                'cardIds' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'ids of cards of this project'],
                'documentIds' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'ids of documents of this project'],
            ],
            'required' => ['kind', 'title'],
        ], minItems: 1)]
        array $items,
        ?string $bridgeId = null,
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
