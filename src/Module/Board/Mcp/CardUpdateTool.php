<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Exception\DomainErrors;
use App\Mcp\FlagGatedToolInterface;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Install\BoardInstallFlags;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Changes a card, one field at a time or several at once.
 *
 * There is no card_delete on purpose. An agent moves a card to done; only a
 * person removes one.
 *
 * @phpstan-import-type CardSummary from CardPayload
 */
#[McpTool(name: self::NAME, description: 'Change a card on the project board. Every field but the card id is optional, and a field you leave out keeps the value it has. Moving a card to done stamps its completion time; moving it out of done clears that stamp. A change of status or priority appends the card to the end of the column it arrives in. pullRequestUrls is the one field where leaving it out and sending an empty list differ: leave it out and the links stay, send an empty list and every link is removed. Origin cannot be changed, because it records who first raised the card. To finish a card, move it to done rather than asking for it to be deleted.')]
final readonly class CardUpdateTool implements FlagGatedToolInterface
{
    public const string NAME = 'card_update';

    public function __construct(
        private BoardFlagGate $gate,
        private BoardSubjectResolver $subjects,
        private UpdateCardHandler $updateCard,
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
     * `string[]|null` not `list<string>|null`: the SDK infers a parameter's
     * JSON-schema `items` from the docblock type and parses only the `T[]` and
     * `array<T>` spellings, so `list<string>` publishes an array of anything.
     * The null default is what lets an omitted list mean "leave the links
     * alone" while an empty list clears them.
     *
     * @param string        $cardId          the id of the card to change, from card_list or card_create
     * @param string|null   $title           a new title
     * @param string|null   $body            a new Markdown body, replacing the old one
     * @param string|null   $type            a new type: feature, bug, security, tooling, docs or idea
     * @param string|null   $priority        a new priority: high, medium or low
     * @param string|null   $status          a new column: backlog, next, in-progress or done
     * @param string[]|null $pullRequestUrls the full set of pull request URLs the card carries; omit to keep the current links, send an empty list to remove them all
     *
     * @return CardSummary
     */
    public function __invoke(string $cardId, ?string $title = null, ?string $body = null, ?string $type = null, ?string $priority = null, ?string $status = null, ?array $pullRequestUrls = null): array
    {
        $this->gate->requireEnabled();

        try {
            $card = $this->subjects->requireCard($cardId, McpBoundProjectVoter::CARD_WRITE);

            $card = ($this->updateCard)(new UpdateCardCommand(
                card: $card,
                title: $title,
                body: $body,
                type: $this->subjects->optionalType($type),
                priority: $this->subjects->optionalPriority($priority),
                status: $this->subjects->optionalStatus($status),
                pullRequestUrls: null === $pullRequestUrls ? null : array_values($pullRequestUrls),
            ));

            return $this->payload->forCard($card);
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The card could not be changed. The error has been logged.', previous: $e);
        }
    }
}
