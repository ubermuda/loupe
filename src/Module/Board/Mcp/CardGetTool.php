<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Mcp\FlagGatedToolInterface;
use App\Module\Board\Install\BoardInstallFlags;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Reads one card, with the pull requests linked to it.
 *
 * @phpstan-import-type CardSummary from CardPayload
 */
#[McpTool(name: self::NAME, description: 'Read one card from the project board, with its full Markdown body and every pull request linked to it. Use a card id from card_list or card_create. The response also carries a number, the short label that counts from 1 inside this project. Use the number to name the card to a person. Pass the cardId here, never the number.')]
final readonly class CardGetTool implements FlagGatedToolInterface
{
    public const string NAME = 'card_get';

    public function __construct(
        private BoardFlagGate $gate,
        private BoardSubjectResolver $subjects,
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
     * @param string $cardId the id of the card to read, from card_list or card_create
     *
     * @return CardSummary
     */
    public function __invoke(string $cardId): array
    {
        $this->gate->requireEnabled();

        try {
            return $this->payload->forCard($this->subjects->requireCard($cardId, McpBoundProjectVoter::CARD_READ));
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The card could not be read. The error has been logged.', previous: $e);
        }
    }
}
