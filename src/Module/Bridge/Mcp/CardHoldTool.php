<?php

declare(strict_types=1);

namespace App\Module\Bridge\Mcp;

use App\Exception\DomainErrors;
use App\Module\Bridge\Command\PauseCardAgentsCommand;
use App\Module\Bridge\Command\PauseCardAgentsHandler;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * Makes one card unmanaged.
 *
 * @phpstan-import-type CardRefusal from BridgeCommandRefusals
 */
#[McpTool(name: self::NAME, description: 'Make a card unmanaged. While the card is unmanaged, the workflow makes no move and starts no work on it, no bridge starts a worker on it, and a person may move it to any column. This stops no live run, so call worker_run_stop to stop one. A queued run waits, and starts when the card is managed again. The card is managed again on card_release, or when the card is deleted. While the workflow engine is off, a move by a person to another column also makes the card managed again. Pass one of cardId or number to name the card, never both. The result has cardId and outcome. Outcome held means that the card is now unmanaged. A result with outcome refused also carries code and message. The codes are: not-found (this project has no such card, and cardId is null when number names no card), already-paused and card-gone (the card was deleted during the call).')]
final readonly class CardHoldTool
{
    public const string NAME = 'card_hold';

    public function __construct(
        private BridgeSubjectResolver $subjects,
        private PauseCardAgentsHandler $pause,
        private BridgeCommandRefusals $refusals,
    ) {
    }

    /**
     * @param string|null $cardId the id of the card; pass it or number, never both
     * @param int|null    $number the card number, the short label that counts from 1 inside this project; pass it instead of cardId
     *
     * @return array{cardId: string, outcome: 'held'}|CardRefusal
     */
    public function __invoke(?string $cardId = null, #[Schema(minimum: 1)] ?int $number = null): array
    {
        try {
            $id = $this->subjects->findCardId($cardId, $number);
            if (null === $id) {
                return $this->refusals->cardNotFound($cardId, $number);
            }

            try {
                ($this->pause)(new PauseCardAgentsCommand($this->subjects->requireProject(), $id, $this->subjects->requireUser(), 'agent'));

                return ['cardId' => (string) $id, 'outcome' => 'held'];
            } catch (DomainErrors $e) {
                return $this->refusals->cardRefused((string) $id, $e);
            }
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The agents on the card could not be paused. The error has been logged.', previous: $e);
        }
    }
}
