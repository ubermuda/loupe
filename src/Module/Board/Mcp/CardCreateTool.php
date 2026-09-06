<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Exception\DomainErrors;
use App\Mcp\FlagGatedToolInterface;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\CardOrigin;
use App\Module\Board\Entity\CardStatus;
use App\Module\Board\Install\BoardInstallFlags;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Puts a card on the project's board.
 *
 * @phpstan-import-type CardSummary from CardPayload
 */
#[McpTool(name: self::NAME, description: 'Add a card to the project board. Give it a title, a Markdown body, a type (feature, bug, security, tooling, docs, idea) and a priority (high, medium, low). It lands in the backlog unless you pass status. Pass pullRequestUrls to link the pull requests that carry the work; a URL from an unrecognised forge is kept as given rather than rejected. The card records origin agent unless you pass human, which says a person raised it and you are only writing it down.')]
final readonly class CardCreateTool implements FlagGatedToolInterface
{
    public const string NAME = 'card_create';

    public function __construct(
        private BoardFlagGate $gate,
        private BoardSubjectResolver $subjects,
        private CreateCardHandler $createCard,
        private CardPayload $payload,
        private BoardToolErrorMessages $errorMessages,
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
        return BoardInstallFlags::FLAG_BOARD_ENABLED;
    }

    /**
     * `string[]` not `list<string>`: the SDK infers a parameter's JSON-schema
     * `items` from the docblock type and parses only the `T[]` and `array<T>`
     * spellings, so `list<string>` publishes an array of anything.
     *
     * @param string      $title           the card title
     * @param string      $body            what the card asks for, in Markdown
     * @param string      $type            one of feature, bug, security, tooling, docs, idea
     * @param string      $priority        one of high, medium, low
     * @param string|null $status          the column the card lands in: backlog, next, in-progress or done; defaults to backlog
     * @param string|null $origin          who raised the card, agent or human; defaults to agent
     * @param string[]    $pullRequestUrls pull request URLs to link to the card
     *
     * @return CardSummary
     */
    public function __invoke(string $title, string $body, string $type, string $priority, ?string $status = null, ?string $origin = null, array $pullRequestUrls = []): array
    {
        $this->gate->requireEnabled();

        try {
            $project = $this->subjects->requireProject();

            $card = ($this->createCard)(new CreateCardCommand(
                project: $project,
                title: $title,
                body: $body,
                type: $this->subjects->requireType($type),
                priority: $this->subjects->requirePriority($priority),
                status: $this->subjects->optionalStatus($status) ?? CardStatus::Backlog,
                // The MCP request authenticates as the project owner, so the
                // tool cannot tell an agent's card from one a person dictated.
                origin: $this->subjects->optionalOrigin($origin) ?? CardOrigin::Agent,
                pullRequestUrls: array_values($pullRequestUrls),
            ));

            return $this->payload->forCard($card);
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The card could not be created. The error has been logged.', previous: $e);
        }
    }
}
