<?php

declare(strict_types=1);

namespace App\Module\Inbox\Mcp;

use App\Exception\DomainErrors;
use App\Mcp\FlagGatedToolInterface;
use App\Module\Inbox\Command\ListInboxActionsCommand;
use App\Module\Inbox\Command\ListInboxActionsHandler;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Service\InboxLinkResolver;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Lists the built-in actions an agent can suggest on an inbox item.
 *
 * @phpstan-type InboxActionSummary array{action: string, effect: string, fitsCard: ?bool}
 */
#[McpTool(name: self::NAME, description: 'List the built-in actions you can suggest to the owner with suggestedActions on inbox_ask: resume-run, retry-card, review, open, send-agent, start-session, run-command and dismiss, each with one line on what it does. Pass cardId to also learn which actions fit that card now; fitsCard is then true or false for each action, and null otherwise. Loupe shows a suggestion only while its action fits the item, so suggest the actions that fit. A card with no open wait item fits open, start-session while a bridge can open a session and no session request of the card is live, and send-agent while its column names stage work that no request or worker does yet.')]
final readonly class InboxActionsTool implements FlagGatedToolInterface
{
    public const string NAME = 'inbox_actions';

    /** The actions a worker can suggest. Add entry is the owner's own step and has no line here. */
    private const array EFFECTS = [
        'resume-run' => 'Wakes the stopped worker so it carries on with its session.',
        'retry-card' => 'Releases the pause of the card so the workflow runs the rule again.',
        'review' => 'Opens the page where the owner reviews the work.',
        'open' => 'Opens the card or the document.',
        'send-agent' => 'Starts a new worker for the stage work of the column the card sits in.',
        'start-session' => 'Opens an interactive session on the card, on a bridge that can open one.',
        'run-command' => 'Asks the bridge of the asking worker to run one named command entry of its rules.yaml after the owner confirms.',
        'dismiss' => 'Closes the item without an action.',
    ];

    public function __construct(
        private InboxFlagGate $gate,
        private InboxSubjectResolver $subjects,
        private ListInboxActionsHandler $list,
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
     * @param string|null $cardId the id of a card of this project, to learn which actions fit it now
     *
     * @return array{actions: list<InboxActionSummary>}
     */
    public function __invoke(?string $cardId = null): array
    {
        $this->gate->requireEnabled();

        try {
            $fitting = ($this->list)(new ListInboxActionsCommand(
                $this->subjects->requireProject(),
                null === $cardId ? null : $this->subjects->requireUuid($cardId, 'card ID'),
            ));

            return ['actions' => array_map(
                static fn (string $action, string $effect): array => [
                    'action' => $action,
                    'effect' => $effect,
                    'fitsCard' => null === $fitting ? null : \in_array(InboxActionKind::from($action), $fitting, true),
                ],
                array_keys(self::EFFECTS),
                self::EFFECTS,
            )];
        } catch (DomainErrors $e) {
            throw new ToolCallException(\sprintf('cardId: %s', InboxLinkResolver::CARD_UNKNOWN === ($e->errors['cardId'] ?? null) ? 'The card id must name a card of this project.' : InboxToolErrorMessages::UNMAPPED), previous: $e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The actions could not be listed. The error has been logged.', previous: $e);
        }
    }
}
