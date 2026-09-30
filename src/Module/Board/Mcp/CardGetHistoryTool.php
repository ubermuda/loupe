<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Mcp\FlagGatedToolInterface;
use App\Module\Board\Command\ListCardEventsCommand;
use App\Module\Board\Command\ListCardEventsHandler;
use App\Module\Board\Command\ListCardsHandler;
use App\Module\Board\Install\BoardInstallFlags;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * Reads one page of a card's history.
 *
 * @phpstan-import-type CardEventSummary from CardEventPayload
 */
#[McpTool(name: self::NAME, description: 'Read the history of one card, newest first: when it was created, each move between columns with who made it and why, and each action the board automation took on it. The response is paginated: pass page to walk further, and keep going while hasMore is true. perPage defaults to 50, with a maximum of 100. Each event carries kind (created, moved, fix-requested, stopped, ready-to-merge or run-finished), occurredAt, and actor with its kind (human, agent, reviewer or system) and the name of the person behind it, or null. from and to name a column by id, slug and label: a move sets both, a creation sets to alone, and every other kind sets neither. cause says why the app moved the card on its own, such as a merged pull request, or is null. pullRequest comes from an automation action, reason from a fix-requested or stopped one, and run holds the whole record of a finished run. The history starts empty, so a change made before it began has no event. Pass the cardId or the number to name the card, never both.')]
final readonly class CardGetHistoryTool implements FlagGatedToolInterface
{
    public const string NAME = 'card_get_history';

    public function __construct(
        private BoardFlagGate $gate,
        private BoardSubjectResolver $subjects,
        private ListCardEventsHandler $listEvents,
        private CardEventPayload $payload,
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
     * @param string|null $cardId  the id of the card to read, from card_list or card_create; pass it or number, never both
     * @param int|null    $number  the card number, the short label that counts from 1 inside this project; pass it instead of cardId
     * @param int         $page    the 1-based page to read
     * @param int         $perPage how many events to return per page
     *
     * @return array{events: list<CardEventSummary>, page: int, perPage: int, total: int, hasMore: bool}
     */
    public function __invoke(?string $cardId = null, #[Schema(minimum: 1)] ?int $number = null, int $page = 1, int $perPage = ListCardsHandler::DEFAULT_PER_PAGE): array
    {
        $this->gate->requireEnabled();

        try {
            $view = ($this->listEvents)(new ListCardEventsCommand(
                $this->subjects->requireCardByIdOrNumber($cardId, $number, McpBoundProjectVoter::CARD_READ),
                $page,
                $perPage,
            ));

            return [
                'events' => $this->payload->forEvents($view->events),
                'page' => $view->page,
                'perPage' => $view->perPage,
                'total' => $view->total,
                'hasMore' => $view->hasMore,
            ];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The card history could not be read. The error has been logged.', previous: $e);
        }
    }
}
