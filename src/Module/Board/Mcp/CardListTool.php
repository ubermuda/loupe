<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Mcp\FlagGatedToolInterface;
use App\Module\Board\Install\BoardInstallFlags;
use App\Module\Board\Repository\CardRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Reads the project's board.
 *
 * @phpstan-import-type CardSummary from CardPayload
 */
#[McpTool(name: self::NAME, description: 'List the cards on the project board. Filter by status (backlog, next, in-progress, done), by type (feature, bug, security, tooling, docs, idea) or by priority (high, medium, low). Every column except done reads in board order, highest priority first and then by position. Done reads newest completion first. The whole board is returned, done cards included, with no time window on them.')]
final readonly class CardListTool implements FlagGatedToolInterface
{
    public const string NAME = 'card_list';

    public function __construct(
        private BoardFlagGate $gate,
        private BoardSubjectResolver $subjects,
        private CardRepository $cards,
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
     * The list is wrapped in a `cards` object key because the MCP spec requires
     * a tool result's `structuredContent` to be a JSON object, not a bare array.
     *
     * @param string|null $status   only cards in this column: backlog, next, in-progress or done
     * @param string|null $type     only cards of this type: feature, bug, security, tooling, docs or idea
     * @param string|null $priority only cards at this priority: high, medium or low
     *
     * @return array{cards: list<CardSummary>, total: int}
     */
    public function __invoke(?string $status = null, ?string $type = null, ?string $priority = null): array
    {
        $this->gate->requireEnabled();

        try {
            $project = $this->subjects->requireProject();

            $cards = $this->cards->findForBoard(
                $project,
                $this->subjects->optionalStatus($status),
                $this->subjects->optionalType($type),
                $this->subjects->optionalPriority($priority),
            );

            return [
                'cards' => array_map($this->payload->forCard(...), $cards),
                'total' => \count($cards),
            ];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The board could not be read. The error has been logged.', previous: $e);
        }
    }
}
