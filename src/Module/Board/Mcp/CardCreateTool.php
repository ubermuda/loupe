<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Exception\DomainErrors;
use App\Module\Board\Command\CardManaged;
use App\Module\Board\Command\ChildDesignRefused;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\ShowCardCommand;
use App\Module\Board\Command\ShowCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardSource;
use App\Module\Board\Entity\CardSourceKind;
use App\Module\Board\Service\ChildDesignChoices;
use App\Module\Workflow\Contract\Actor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * Puts a card on the project's board.
 *
 * @phpstan-import-type CardSummary from CardPayload
 */
#[McpTool(name: self::NAME, description: 'Add a card to the project board. Give it a title, a Markdown body and a type. board_columns lists the types this project declares, with the capabilities of each, and a type it does not declare is refused. A type with the children capability groups the child cards that together build one feature. Pass parentCardId to put the new card under a card of such a type in this project. Only a card of a type with the children capability can be a parent, and such a card cannot have a parent. laneEnabled says whether the board draws a lane for a card of a type with the lane capability, and it defaults to true. With no status, the card lands in Backlog, whose slug is backlog. The board does not draw Backlog as a column, and Backlog has its own page. To place the card elsewhere, pass status, the slug of a column. Each board has its own columns, and board_columns lists them. A terminal column is where finished work goes, and a card created in one gets a completion time. Pass pullRequestUrls to link the pull requests that carry the work; a URL from an unrecognised forge is kept as given rather than rejected. Pass documentIds to link the documents the work is written up in; unlike a pull request URL, an id naming no document of this project is refused rather than kept. The card records reporter agent unless you pass human, which says a person raised it and you are only writing it down. Passing reviewer is refused: the site-review widget writes that value, and it means somebody the app could not name raised the card. origin is the old name for reporter and still works for one release, so move to reporter; when you send both, reporter wins. Pass relatedCards to link other cards of this project. Each entry takes a cardId and a kind: relates-to (the default), blocks or blocked-by. A link to the card itself, to a card outside this project, or to one card twice is refused. childDesign says whether the tech design of the parent covers the card, for a card under a parent whose tech design is approved. Pass inherit to link that design: choose it for a fix inside a decision the design already made, and the card starts with the other children. Pass own for a card that asks an open question or changes a decision of the design: the card goes to Tech design and gets a design of its own, so pass no status with it. Without the choice, the owner answers a question about the card. When the owner would get that question, the call is refused until you pass the choice. The response carries a number, the short label that counts from 1 inside this project. Say "card 42" and name a branch after it. It is not the cardId, and another project has its own card 42. card_get and card_update accept it, so use the cardId or the number to read or change it.')]
final readonly class CardCreateTool
{
    public const string NAME = 'card_create';

    public function __construct(
        private BoardSubjectResolver $subjects,
        private CreateCardHandler $createCard,
        private ShowCardHandler $showCard,
        private CardPayload $payload,
        private BoardToolErrorMessages $errorMessages,
        private AgentRunSource $runSource,
        private ChildDesignDecision $childDesign,
        private ChildDesignChoices $choices,
    ) {
    }

    /**
     * `string[]` not `list<string>`: the SDK infers a parameter's JSON-schema
     * `items` from the docblock type and parses only the `T[]` and `array<T>`
     * spellings, so `list<string>` publishes an array of anything.
     *
     * @param string       $title           the card title
     * @param string       $body            what the card asks for, in Markdown
     * @param string       $type            a type key from board_columns
     * @param string|null  $status          the slug of the column the card lands in, from board_columns; defaults to backlog
     * @param string|null  $reporter        who raised the card, agent or human; defaults to agent
     * @param string[]     $pullRequestUrls pull request URLs to link to the card
     * @param string[]     $documentIds     ids of documents in this project to link; any other id is refused
     * @param string|null  $origin          the old name for reporter, accepted for one release; reporter wins when both are sent
     * @param array<mixed> $relatedCards    cards of this project to link, each with a cardId and a kind
     * @param string|null  $parentCardId    the id of a card of this project, of a type with the children capability, that the card belongs to; omit it for no parent
     * @param bool|null    $laneEnabled     whether the board draws a lane for this card, when its type has the lane capability; defaults to true
     * @param string|null  $childDesign     inherit or own, for a card under a parent whose tech design is approved; inherit links that design, and own moves the card to Tech design
     *
     * @return CardSummary
     */
    public function __invoke(string $title, string $body, string $type, ?string $status = null, ?string $reporter = null, array $pullRequestUrls = [], array $documentIds = [], ?string $origin = null, #[Schema(items: BoardSubjectResolver::RELATED_CARD_ITEM)] array $relatedCards = [], ?string $parentCardId = null, ?bool $laneEnabled = null, #[Schema(enum: ChildDesignChoices::CHOICES)] ?string $childDesign = null): array
    {
        $reporter ??= $origin;

        try {
            $project = $this->subjects->requireProject();

            $column = $this->subjects->optionalColumn($project, $status);
            $choice = $this->childDesign->resolve($parentCardId, null, $childDesign, array_values($documentIds), null !== $status);

            $create = fn (): Card => ($this->createCard)(new CreateCardCommand(
                project: $project,
                title: $title,
                body: $body,
                type: $this->subjects->requireType($project, $type),
                column: $column,
                // The MCP request authenticates as the project owner, so the
                // tool cannot tell an agent's card from one a person dictated.
                reporter: $this->subjects->optionalClaimedReporter($reporter) ?? Actor::Agent,
                pullRequestUrls: array_values($pullRequestUrls),
                documentIds: array_values($documentIds),
                relatedCards: $this->subjects->requireRelatedCards($relatedCards),
                parentCardId: $parentCardId,
                laneEnabled: $laneEnabled,
                // A claimed human reporter is not the account that makes this call.
                actor: Actor::Agent,
                source: $this->runSource->forProject($project) ?? new CardSource(CardSourceKind::Agent),
            ));
            $card = null === $choice ? $create() : $this->choices->write($create, $choice);

            $view = ($this->showCard)(new ShowCardCommand($card));

            return $this->payload->forCard($view);
        } catch (ChildDesignRefused $e) {
            throw new ToolCallException($e->getMessage(), previous: $e);
        } catch (CardManaged $e) {
            throw new ToolCallException(CardManaged::AGENT_MESSAGE, previous: $e);
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The card could not be created. The error has been logged.', previous: $e);
        }
    }
}
