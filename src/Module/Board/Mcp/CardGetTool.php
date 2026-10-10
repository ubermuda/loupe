<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Module\Board\Command\ShowCardCommand;
use App\Module\Board\Command\ShowCardHandler;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * Reads one card, with the pull requests linked to it.
 *
 * @phpstan-import-type CardSummary from CardPayload
 */
#[McpTool(name: self::NAME, description: 'Read one card from the project board, with its full Markdown body and every pull request linked to it. Each pull request carries state, the last state Loupe read from the forge: state, draft, checks, failedChecks, mergeability, review, readyToMerge and refreshedAt. state is null when Loupe holds no reading, such as for a link it cannot parse or a pull request it has not read yet. siteReviewComments lists the feedback items that belong to the card, each with its id, url, anchors, body, hasDrawing, status, context and createdAt; mark one addressed with feedback_mark_addressed. relatedCards lists the linked cards, each with its cardId, number, title, status and kind as this card reads it: a blocks link from card A reads blocked-by from card B. parent names the card the card belongs to, or null. A card whose type has the children capability lists its children, and progress counts how many of them sit in a terminal column (done) out of all of them (total); progress is null for a type without that capability. laneEnabled says whether the board draws a lane for an epic. pause is null, or names the active workflow pause that holds the card: pauseId, kind (rule, retries, work-limit, work-timeout or work-stopped), reason, ruleId and since. state is null, or what the card needs now on the board: kind (stuck, needs-you, working or waiting), code (why), since (when that reason began, or null when Loupe stores none), reason (one sentence that says why) and others (the other reasons that apply, each with kind, code, reason and since). A card in a terminal column has no state. Use a card id from card_list or card_create. The response also carries a number, the short label that counts from 1 inside this project. Use the number to name the card to a person. Pass the cardId or the number to read a card, never both.')]
final readonly class CardGetTool
{
    public const string NAME = 'card_get';

    public function __construct(
        private BoardSubjectResolver $subjects,
        private ShowCardHandler $showCard,
        private CardPayload $payload,
    ) {
    }

    /**
     * @param string|null $cardId the id of the card to read, from card_list or card_create; pass it or number, never both
     * @param int|null    $number the card number, the short label that counts from 1 inside this project; pass it instead of cardId
     *
     * @return CardSummary
     */
    public function __invoke(?string $cardId = null, #[Schema(minimum: 1)] ?int $number = null): array
    {
        try {
            $view = ($this->showCard)(new ShowCardCommand(
                $this->subjects->requireCardByIdOrNumber($cardId, $number, McpBoundProjectVoter::CARD_READ),
            ));

            return $this->payload->forCard($view);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The card could not be read. The error has been logged.', previous: $e);
        }
    }
}
