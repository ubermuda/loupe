<?php

declare(strict_types=1);

namespace App\Module\Workflow\Mcp;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Mcp\BoardSubjectResolver;
use App\Module\Bridge\Mcp\BridgeCommandRefusals;
use App\Module\Bridge\Mcp\BridgeSubjectResolver;
use App\Module\Workflow\Command\ReleaseWorkflowPauseCommand;
use App\Module\Workflow\Command\ReleaseWorkflowPauseHandler;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Uid\Uuid;

/**
 * Ends the workflow pause of one card, so the paused rule runs again.
 *
 * @phpstan-import-type CardRefusal from BridgeCommandRefusals
 */
#[McpTool(name: self::NAME, description: 'Lift the workflow pause of a card, so the paused rule runs again with a fresh budget. The workflow pauses a card when a rule used up its retries (kind retries) or its work limit (kind work-limit), when no bridge took its work in time (kind work-timeout), or when a worker stopped with a refusal that the workflow does not retry (kind work-stopped). Lift a pause only when its cause is gone, for example after an outage. A pause of kind rule ends only on its own release condition, so it is refused. This tool differs from card_release, which makes a card managed again after card_hold. card_get shows the pause of a card in its pause field, and card_list with paused true lists the paused cards. Pass one of cardId or number to name the card, never both. Pass pauseId to lift that pause only, so a pause that changed since you read it is refused. The result has cardId and outcome. Outcome released also carries the kind, reason and ruleId of the pause that ended. A result with outcome refused also carries code and message. The codes are: not-found (this project has no such card, and cardId is null when number names no card), card-unmanaged (the card is unmanaged, or the workflow is off for the project), not-paused, pause-changed (the active pause is not pauseId) and kind-not-releasable.')]
final readonly class CardPauseReleaseTool
{
    public const string NAME = 'card_pause_release';

    public function __construct(
        private BridgeSubjectResolver $subjects,
        private BoardSubjectResolver $boardSubjects,
        private ReleaseWorkflowPauseHandler $release,
        private BridgeCommandRefusals $refusals,
    ) {
    }

    /**
     * @param string|null $cardId  the id of the card; pass it or number, never both
     * @param int|null    $number  the card number, the short label that counts from 1 inside this project; pass it instead of cardId
     * @param string|null $pauseId the pauseId of the pause that card_get shows; omit it to lift the active pause
     *
     * @return array{cardId: string, outcome: 'released', kind: string, reason: string, ruleId: string}|CardRefusal
     */
    public function __invoke(?string $cardId = null, #[Schema(minimum: 1)] ?int $number = null, ?string $pauseId = null): array
    {
        try {
            $pause = null === $pauseId ? null : $this->parsePauseId($pauseId);
            $id = $this->subjects->findCardId($cardId, $number);
            if (null === $id) {
                return $this->refusals->cardNotFound($cardId, $number);
            }
            $card = $this->boardSubjects->requireCard((string) $id, McpBoundProjectVoter::CARD_WRITE);

            try {
                $released = ($this->release)(new ReleaseWorkflowPauseCommand($card, $this->subjects->requireUser(), CardReporter::Agent, $pause));

                return [
                    'cardId' => (string) $id,
                    'outcome' => 'released',
                    'kind' => $released->kind->value,
                    'reason' => $released->reason,
                    'ruleId' => $released->ruleId,
                ];
            } catch (DomainErrors $e) {
                // A card deleted during the call takes its pause with it, which reads as not paused.
                if (null === $this->subjects->findCardId((string) $id, null)) {
                    return $this->refusals->cardNotFound((string) $id, null);
                }

                return $this->refusals->cardRefused((string) $id, $e);
            }
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The workflow pause could not be released. The error has been logged.', previous: $e);
        }
    }

    private function parsePauseId(string $pauseId): Uuid
    {
        try {
            return Uuid::fromString($pauseId);
        } catch (\InvalidArgumentException $e) {
            throw new ToolCallException(\sprintf('"%s" is not a valid pause ID. Pass the pauseId of the pause of card_get.', $pauseId), previous: $e);
        }
    }
}
