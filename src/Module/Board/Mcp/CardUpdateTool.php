<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Exception\DomainErrors;
use App\Mcp\FlagGatedToolInterface;
use App\Module\Board\Command\EpicChildrenOpen;
use App\Module\Board\Command\ShowCardCommand;
use App\Module\Board\Command\ShowCardHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Install\BoardInstallFlags;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * Changes a card, one field at a time or several at once.
 *
 * There is no card_delete on purpose. An agent moves a card to a terminal
 * column; only a person removes one.
 *
 * @phpstan-import-type CardSummary from CardPayload
 */
#[McpTool(name: self::NAME, description: 'Change a card on the project board. Pass one of cardId or number to name the card. Every other field is optional, and a field you leave out keeps the value it has. status takes the slug of a column on this board. Each board has its own columns, and board_columns lists them. A terminal column is where finished work goes. Moving a card to a terminal column stamps its completion time; moving it back to a column that is not terminal clears that stamp. A change of status appends the card to the end of the column it arrives in. pullRequestUrls and documentIds are the fields where leaving one out and sending an empty list differ: leave one out and those links stay, send an empty list and every link of that kind is removed. A documentId naming no document of this project is refused. relatedCards works the same way, and it replaces every link that touches the card, including links written from the other card. Send the whole set: the relatedCards of card_get goes back unchanged. Each entry takes a cardId and a kind (relates-to, blocks or blocked-by). parentCardId puts the card under an epic of this project: omit it to keep the parent, send an empty string to clear it, or send the id of an epic to set it. Only an epic can be a parent, an epic cannot have a parent, and an epic with children keeps its type. laneEnabled says whether the board draws a lane for an epic. Reporter cannot be changed, because it records who first raised the card. To finish a card, move it to a terminal column rather than asking for it to be deleted. The card number does not change, and you cannot set it. It is the short label that counts from 1 inside this project. This tool takes the cardId or the number, never both.')]
final readonly class CardUpdateTool implements FlagGatedToolInterface
{
    public const string NAME = 'card_update';

    public function __construct(
        private BoardFlagGate $gate,
        private BoardSubjectResolver $subjects,
        private UpdateCardHandler $updateCard,
        private ShowCardHandler $showCard,
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
     * @param string|null       $cardId          the id of the card to change, from card_list or card_create; pass it or number, never both
     * @param int|null          $number          the card number, the short label that counts from 1 inside this project; pass it instead of cardId
     * @param string|null       $title           a new title
     * @param string|null       $body            a new Markdown body, replacing the old one
     * @param string|null       $type            a new type: feature, bug, security, tooling, docs, idea, epic or site-review
     * @param string|null       $status          the slug of a new column on this board; board_columns lists the slugs
     * @param string[]|null     $pullRequestUrls the full set of pull request URLs the card carries; omit to keep the current links, send an empty list to remove them all
     * @param string[]|null     $documentIds     the full set of document ids the card carries; omit to keep the current links, send an empty list to remove them all
     * @param array<mixed>|null $relatedCards    the full set of card links; omit to keep them, send an empty list to remove them all
     * @param string|null       $parentCardId    the id of an epic of this project; omit to keep the parent, send an empty string to clear it
     * @param bool|null         $laneEnabled     whether the board draws a lane for this epic; omit to keep the setting
     *
     * @return CardSummary
     */
    public function __invoke(?string $cardId = null, #[Schema(minimum: 1)] ?int $number = null, ?string $title = null, ?string $body = null, ?string $type = null, ?string $status = null, ?array $pullRequestUrls = null, ?array $documentIds = null, #[Schema(items: BoardSubjectResolver::RELATED_CARD_ITEM)] ?array $relatedCards = null, ?string $parentCardId = null, ?bool $laneEnabled = null): array
    {
        $this->gate->requireEnabled();

        try {
            $card = $this->subjects->requireCardByIdOrNumber($cardId, $number, McpBoundProjectVoter::CARD_WRITE);

            $card = ($this->updateCard)(new UpdateCardCommand(
                card: $card,
                actor: CardReporter::Agent,
                title: $title,
                body: $body,
                type: $this->subjects->optionalType($type),
                column: $this->subjects->optionalColumn($card->project, $status),
                pullRequestUrls: null === $pullRequestUrls ? null : array_values($pullRequestUrls),
                documentIds: null === $documentIds ? null : array_values($documentIds),
                relatedCards: $this->subjects->optionalRelatedCards($relatedCards),
                parentCardId: $parentCardId,
                laneEnabled: $laneEnabled,
            ));

            $view = ($this->showCard)(new ShowCardCommand($card));

            return $this->payload->forCard($view);
        } catch (EpicChildrenOpen $e) {
            throw new ToolCallException(\sprintf('status: This epic has open child cards %s. Move each of them to a terminal column first.', $e->cardList()), previous: $e);
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The card could not be changed. The error has been logged.', previous: $e);
        }
    }
}
