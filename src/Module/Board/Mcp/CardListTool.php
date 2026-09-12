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
 * @phpstan-import-type CardListSummary from CardPayload
 */
#[McpTool(name: self::NAME, description: 'List the cards on the project board. Filter by status (backlog, next, in-progress, done), by type (feature, bug, security, tooling, docs, idea), by priority (high, medium, low) or by reporter (human, agent, reviewer), who raised the card. Every column except done reads in board order, highest priority first and then by position. Done reads newest completion first, with no time window on it. Each row is a summary: cardId, number, title, type, priority, status, reporter and updatedAt. Pass full to get the body and the pull request, document and site-review links as well, which is far larger. Paginated: pass page to walk further, and keep going while hasMore is true. Every card carries a number, the short label that counts from 1 inside this project. Use it to name a card to a person, and use the cardId to read or change it.')]
final readonly class CardListTool implements FlagGatedToolInterface
{
    public const string NAME = 'card_list';

    public const int DEFAULT_PER_PAGE = 50;

    public const int MAX_PER_PAGE = 100;

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
     * The page is cut from the returned array rather than from SQL. The board
     * order is up to four differently-ordered queries concatenated in PHP, one
     * per column, so one LIMIT cannot express it.
     *
     * @param string|null $status   only cards in this column: backlog, next, in-progress or done
     * @param string|null $type     only cards of this type: feature, bug, security, tooling, docs or idea
     * @param string|null $priority only cards at this priority: high, medium or low
     * @param string|null $reporter only cards raised by this reporter: human, agent or reviewer
     * @param int         $page     the 1-based page to read
     * @param int         $perPage  how many cards to return per page
     * @param bool        $full     return the whole card, body and links included, rather than the summary
     *
     * @return ($full is true ? array{cards: list<CardSummary>, page: int, perPage: int, total: int, hasMore: bool} : array{cards: list<CardListSummary>, page: int, perPage: int, total: int, hasMore: bool})
     */
    public function __invoke(?string $status = null, ?string $type = null, ?string $priority = null, ?string $reporter = null, int $page = 1, int $perPage = self::DEFAULT_PER_PAGE, bool $full = false): array
    {
        $this->gate->requireEnabled();

        // Clamped rather than rejected: an out-of-range page from an agent
        // should return an empty page, not fail the tool call.
        $page = max(1, $page);
        $perPage = min(self::MAX_PER_PAGE, max(1, $perPage));

        try {
            $project = $this->subjects->requireProject();

            $cards = $this->cards->findForBoard(
                $project,
                $this->subjects->optionalStatus($status),
                $this->subjects->optionalType($type),
                $this->subjects->optionalPriority($priority),
                $this->subjects->optionalReporter($reporter),
            );

            $total = \count($cards);
            // A page past the end reads empty. The offset is capped before the
            // multiplication, because a page near PHP_INT_MAX would overflow to
            // a float and array_slice() then refuses it.
            $offset = $page - 1 > intdiv($total, $perPage) ? $total : ($page - 1) * $perPage;
            $slice = \array_slice($cards, $offset, $perPage);
            $meta = ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'hasMore' => $offset + $perPage < $total];

            if ($full) {
                return ['cards' => $this->payload->forCards($slice), ...$meta];
            }

            return ['cards' => $this->payload->forCardList($slice), ...$meta];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The board could not be read. The error has been logged.', previous: $e);
        }
    }
}
