<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Mcp\FlagGatedToolInterface;
use App\Module\Board\Command\CloseCardRunCommand;
use App\Module\Board\Command\CloseCardRunHandler;
use App\Module\Board\Install\BoardInstallFlags;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

#[McpTool(name: self::NAME, description: 'Close the interactive run that card_run_open recorded on a card. Call it when the interactive session ends. Pass $CLAUDE_CODE_SESSION_ID as sessionId. Pass one of cardId or number to name the card, never both. A bridge rule with card: { interactiveRun: false } then acts on the card again. A second call changes nothing and returns the closed run. run is null when the session has no run on the card.')]
final readonly class CardRunCloseTool implements FlagGatedToolInterface
{
    public const string NAME = 'card_run_close';

    public function __construct(
        private BoardFlagGate $gate,
        private BoardSubjectResolver $subjects,
        private CloseCardRunHandler $closeRun,
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
     * @param string      $sessionId the id of the interactive session, the value of $CLAUDE_CODE_SESSION_ID
     * @param string|null $cardId    the id of the card; pass it or number, never both
     * @param int|null    $number    the card number, the short label that counts from 1 inside this project; pass it instead of cardId
     *
     * @return array{cardId: string, number: int, run: array{runId: string, state: string}|null}
     */
    public function __invoke(string $sessionId, ?string $cardId = null, #[Schema(minimum: 1)] ?int $number = null): array
    {
        $this->gate->requireEnabled();

        try {
            $session = $this->subjects->requireSessionId($sessionId);
            $card = $this->subjects->requireCardByIdOrNumber($cardId, $number, McpBoundProjectVoter::CARD_WRITE);

            $run = ($this->closeRun)(new CloseCardRunCommand($card, $session));

            return [
                'cardId' => (string) $card->id,
                'number' => $card->number,
                'run' => null === $run ? null : ['runId' => (string) $run->id, 'state' => $run->state->value],
            ];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The run could not be closed. The error has been logged.', previous: $e);
        }
    }
}
