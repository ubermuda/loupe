<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Mcp\FlagGatedToolInterface;
use App\Module\Board\Command\ShowCardCommand;
use App\Module\Board\Command\ShowCardHandler;
use App\Module\Board\Install\BoardInstallFlags;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * Reads one card, with the pull requests linked to it.
 *
 * @phpstan-import-type CardSummary from CardPayload
 */
#[McpTool(name: self::NAME, description: 'Read one card from the project board, with its full Markdown body and every pull request linked to it. relatedCards lists the linked cards, each with its cardId, number, title, status and kind as this card reads it: a blocks link from card A reads blocked-by from card B. parent names the epic the card belongs to, or null. An epic lists its children, and progress counts how many of them sit in a terminal column (done) out of all of them (total); progress is null for any other type. laneEnabled says whether the board draws a lane for an epic. Use a card id from card_list or card_create. The response also carries a number, the short label that counts from 1 inside this project. Use the number to name the card to a person. Pass the cardId or the number to read a card, never both.')]
final readonly class CardGetTool implements FlagGatedToolInterface
{
    public const string NAME = 'card_get';

    public function __construct(
        private BoardFlagGate $gate,
        private BoardSubjectResolver $subjects,
        private ShowCardHandler $showCard,
        private CardPayload $payload,
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
     * @param string|null $cardId the id of the card to read, from card_list or card_create; pass it or number, never both
     * @param int|null    $number the card number, the short label that counts from 1 inside this project; pass it instead of cardId
     *
     * @return CardSummary
     */
    public function __invoke(?string $cardId = null, #[Schema(minimum: 1)] ?int $number = null): array
    {
        $this->gate->requireEnabled();

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
