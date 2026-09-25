<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Exception\DomainErrors;
use App\Mcp\FlagGatedToolInterface;
use App\Module\Board\Command\OpenCardRunCommand;
use App\Module\Board\Command\OpenCardRunHandler;
use App\Module\Board\Command\ShowCardCommand;
use App\Module\Board\Command\ShowCardHandler;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Install\BoardInstallFlags;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

#[McpTool(name: self::NAME, description: 'Record an open interactive session on a card. Call it when an interactive skill starts work on a card. Pass $CLAUDE_CODE_SESSION_ID as sessionId, and the skill name as name, for example loupe:product-design. Pass one of cardId or number to name the card, never both. While the run is open, a bridge rule with card: { interactiveRun: false } skips the card. status is optional, and takes the slug of a column on this board. It moves the card in the same step, and this move keeps the run open. Every later move of the card to another column closes the run. A move inside the same column closes nothing. Call card_run_close when the session ends. A second call for the same session and card that does not move the card returns the run that is already open. A second call whose status moves the card to another column closes that run and opens a new one.')]
final readonly class CardRunOpenTool implements FlagGatedToolInterface
{
    public const string NAME = 'card_run_open';

    public function __construct(
        private BoardFlagGate $gate,
        private BoardSubjectResolver $subjects,
        private OpenCardRunHandler $openRun,
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
     * @param string      $sessionId the id of the interactive session, the value of $CLAUDE_CODE_SESSION_ID
     * @param string      $name      the name of the skill that runs the session, for example loupe:product-design
     * @param string|null $cardId    the id of the card; pass it or number, never both
     * @param int|null    $number    the card number, the short label that counts from 1 inside this project; pass it instead of cardId
     * @param string|null $status    the slug of a column to move the card to in the same step; board_columns lists the slugs
     *
     * @return array<string, mixed> a CardSummary with a run key, which holds runId, state and name
     */
    public function __invoke(string $sessionId, string $name, ?string $cardId = null, #[Schema(minimum: 1)] ?int $number = null, ?string $status = null): array
    {
        $this->gate->requireEnabled();

        try {
            $session = $this->subjects->requireSessionId($sessionId);

            $card = $this->subjects->requireCardByIdOrNumber($cardId, $number, McpBoundProjectVoter::CARD_WRITE);

            $opened = ($this->openRun)(new OpenCardRunCommand(
                card: $card,
                actor: CardReporter::Agent,
                sessionId: $session,
                name: $name,
                column: $this->subjects->optionalColumn($card->project, $status),
            ));
            $run = $opened->run;

            $view = ($this->showCard)(new ShowCardCommand($opened->card));

            return [
                ...$this->payload->forCard($view->card, $view->siteReviewLinks, $view->relatedCards),
                'run' => ['runId' => (string) $run->id, 'state' => $run->state->value, 'name' => $run->ruleName],
            ];
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The run could not be opened. The error has been logged.', previous: $e);
        }
    }
}
