<?php

declare(strict_types=1);

namespace App\Module\Bridge\Mcp;

use App\Exception\DomainErrors;
use App\Module\Bridge\Command\ReleaseCardAgentsCommand;
use App\Module\Bridge\Command\ReleaseCardAgentsHandler;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * Makes one unmanaged card managed again.
 *
 * @phpstan-import-type CardRefusal from BridgeCommandRefusals
 */
#[McpTool(name: self::NAME, description: 'Manage a card again that card_hold made unmanaged. The queued runs on the card then start. The workflow takes the card as it is now, so a condition that is already true does not fire. This tool does not lift a workflow pause, which the workflow makes when a rule runs out of retries or work budget. Call card_pause_release for that. Pass one of cardId or number to name the card, never both. The result has cardId and outcome. Outcome released means that the card is managed again. A result with outcome refused also carries code and message. The codes are: not-found (this project has no such card, and cardId is null when number names no card) and not-paused.')]
final readonly class CardReleaseTool
{
    public const string NAME = 'card_release';

    public function __construct(
        private BridgeSubjectResolver $subjects,
        private ReleaseCardAgentsHandler $release,
        private BridgeCommandRefusals $refusals,
    ) {
    }

    /**
     * @param string|null $cardId the id of the card; pass it or number, never both
     * @param int|null    $number the card number, the short label that counts from 1 inside this project; pass it instead of cardId
     *
     * @return array{cardId: string, outcome: 'released'}|CardRefusal
     */
    public function __invoke(?string $cardId = null, #[Schema(minimum: 1)] ?int $number = null): array
    {
        try {
            $id = $this->subjects->findCardId($cardId, $number);
            if (null === $id) {
                return $this->refusals->cardNotFound($cardId, $number);
            }

            try {
                ($this->release)(new ReleaseCardAgentsCommand($this->subjects->requireProject(), $id, $this->subjects->requireUser(), 'agent'));

                return ['cardId' => (string) $id, 'outcome' => 'released'];
            } catch (DomainErrors $e) {
                // A card deleted during the call takes its hold with it, which reads as not paused.
                if (null === $this->subjects->findCardId((string) $id, null)) {
                    return $this->refusals->cardNotFound((string) $id, null);
                }

                return $this->refusals->cardRefused((string) $id, $e);
            }
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The agents on the card could not be released. The error has been logged.', previous: $e);
        }
    }
}
