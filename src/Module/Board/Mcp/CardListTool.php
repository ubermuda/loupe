<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Mcp\FlagGatedToolInterface;
use App\Module\Board\Command\ListCardsCommand;
use App\Module\Board\Command\ListCardsHandler;
use App\Module\Board\Install\BoardInstallFlags;
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

    public function __construct(
        private BoardFlagGate $gate,
        private BoardSubjectResolver $subjects,
        private ListCardsHandler $listCards,
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
     * @param string|null $reporter only cards raised by this reporter: human, agent or reviewer
     * @param int         $page     the 1-based page to read
     * @param int         $perPage  how many cards to return per page
     * @param bool        $full     return the whole card, body and links included, rather than the summary
     *
     * @return ($full is true ? array{cards: list<CardSummary>, page: int, perPage: int, total: int, hasMore: bool} : array{cards: list<CardListSummary>, page: int, perPage: int, total: int, hasMore: bool})
     */
    public function __invoke(?string $status = null, ?string $type = null, ?string $priority = null, ?string $reporter = null, int $page = 1, int $perPage = ListCardsHandler::DEFAULT_PER_PAGE, bool $full = false): array
    {
        $this->gate->requireEnabled();

        try {
            $view = ($this->listCards)(new ListCardsCommand(
                project: $this->subjects->requireProject(),
                status: $this->subjects->optionalStatus($status),
                type: $this->subjects->optionalType($type),
                priority: $this->subjects->optionalPriority($priority),
                reporter: $this->subjects->optionalReporter($reporter),
                page: $page,
                perPage: $perPage,
            ));

            $meta = ['page' => $view->page, 'perPage' => $view->perPage, 'total' => $view->total, 'hasMore' => $view->hasMore];

            if ($full) {
                return ['cards' => $this->payload->forCards($view->cards), ...$meta];
            }

            return ['cards' => $this->payload->forCardList($view->cards), ...$meta];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The board could not be read. The error has been logged.', previous: $e);
        }
    }
}
