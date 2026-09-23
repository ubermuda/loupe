<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Exception\DomainErrors;
use App\Mcp\FlagGatedToolInterface;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\ShowCardCommand;
use App\Module\Board\Command\ShowCardHandler;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Install\BoardInstallFlags;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * Puts a card on the project's board.
 *
 * @phpstan-import-type CardSummary from CardPayload
 */
#[McpTool(name: self::NAME, description: 'Add a card to the project board. Give it a title, a Markdown body and a type (feature, bug, security, tooling, docs, idea). It lands in the default column of the board unless you pass status, the slug of a column. Each board has its own columns, and board_columns lists them. A terminal column is where finished work goes, and a card created in one gets a completion time. Pass pullRequestUrls to link the pull requests that carry the work; a URL from an unrecognised forge is kept as given rather than rejected. Pass documentIds to link the documents the work is written up in; unlike a pull request URL, an id naming no document of this project is refused rather than kept. The card records reporter agent unless you pass human, which says a person raised it and you are only writing it down. Passing reviewer is refused: the site-review widget writes that value, and it means somebody the app could not name raised the card. origin is the old name for reporter and still works for one release, so move to reporter; when you send both, reporter wins. Pass relatedCards to link other cards of this project, each with a kind: relates-to, blocks or blocked-by. The response carries a number, the short label that counts from 1 inside this project. Say "card 42" and name a branch after it. It is not the cardId, and another project has its own card 42. card_get and card_update accept it, so use the cardId or the number to read or change it.')]
final readonly class CardCreateTool implements FlagGatedToolInterface
{
    public const string NAME = 'card_create';

    public function __construct(
        private BoardFlagGate $gate,
        private BoardSubjectResolver $subjects,
        private CreateCardHandler $createCard,
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
     * `string[]` not `list<string>`: the SDK infers a parameter's JSON-schema
     * `items` from the docblock type and parses only the `T[]` and `array<T>`
     * spellings, so `list<string>` publishes an array of anything.
     *
     * @param string       $title           the card title
     * @param string       $body            what the card asks for, in Markdown
     * @param string       $type            one of feature, bug, security, tooling, docs, idea
     * @param string|null  $status          the slug of the column the card lands in, from board_columns; defaults to the board's default column
     * @param string|null  $reporter        who raised the card, agent or human; defaults to agent
     * @param string[]     $pullRequestUrls pull request URLs to link to the card
     * @param string[]     $documentIds     ids of documents in this project to link; any other id is refused
     * @param string|null  $origin          the old name for reporter, accepted for one release; reporter wins when both are sent
     * @param array<mixed> $relatedCards    cards of this project to link, each with a cardId and a kind
     *
     * @return CardSummary
     */
    public function __invoke(string $title, string $body, string $type, ?string $status = null, ?string $reporter = null, array $pullRequestUrls = [], array $documentIds = [], ?string $origin = null, #[Schema(items: BoardSubjectResolver::RELATED_CARD_ITEM)] array $relatedCards = []): array
    {
        $this->gate->requireEnabled();

        $reporter ??= $origin;

        try {
            $project = $this->subjects->requireProject();

            $card = ($this->createCard)(new CreateCardCommand(
                project: $project,
                title: $title,
                body: $body,
                type: $this->subjects->requireType($type),
                column: $this->subjects->optionalColumn($project, $status),
                // The MCP request authenticates as the project owner, so the
                // tool cannot tell an agent's card from one a person dictated.
                reporter: $this->subjects->optionalClaimedReporter($reporter) ?? CardReporter::Agent,
                pullRequestUrls: array_values($pullRequestUrls),
                documentIds: array_values($documentIds),
                relatedCards: $this->subjects->requireRelatedCards($relatedCards),
            ));

            $view = ($this->showCard)(new ShowCardCommand($card));

            return $this->payload->forCard($view->card, $view->siteReviewLinks);
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The card could not be created. The error has been logged.', previous: $e);
        }
    }
}
