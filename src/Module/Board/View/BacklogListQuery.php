<?php

declare(strict_types=1);

namespace App\Module\Board\View;

use App\Module\Board\Command\ListBacklogCardsHandler;
use App\Module\Board\Entity\CardType;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\Uid\Uuid;

/**
 * The page and the filters of the Backlog page. Every link and redirect that
 * must keep them reads routeParams(), so a new filter reaches all of them.
 * An unknown value reads as no filter, so a hand-edited URL shows the list.
 */
final readonly class BacklogListQuery
{
    public const string NO_EPIC = 'none';

    public function __construct(
        public int $page = 1,
        public ?string $search = null,
        public ?CardType $type = null,
        /** Null is any epic, NO_EPIC is no epic, and a card id is that epic. */
        public ?string $epic = null,
        public BacklogSort $sort = BacklogSort::Rank,
    ) {
    }

    /** @param InputBag<string> $query */
    public static function fromQuery(InputBag $query): self
    {
        $search = trim($query->getString('search'));
        $epic = strtolower(trim($query->getString('epic')));

        return new self(
            page: max(1, $query->getInt('page', 1)),
            search: '' === $search ? null : $search,
            type: CardType::tryFrom($query->getString('type')),
            epic: self::NO_EPIC === $epic || Uuid::isValid($epic) ? $epic : null,
            sort: BacklogSort::tryFrom($query->getString('sort')) ?? BacklogSort::Rank,
        );
    }

    public function withPage(int $page): self
    {
        return clone ($this, ['page' => $page]);
    }

    /** Whether a filter narrows the list, which separates an empty Backlog from no match. */
    /**
     * Whether this page must be drawn again after rows left it, from the count
     * the filters match after the move. A page before the last takes rows from
     * the next page, and a page that lost every row shows another page or the
     * empty state. Only the last page keeps its other rows as they are.
     */
    public function redrawsAfterMoving(int $moved, int $filteredTotal): bool
    {
        $rowsLeft = $filteredTotal - ($this->page - 1) * ListBacklogCardsHandler::PER_PAGE;

        return $rowsLeft <= 0 || $rowsLeft + $moved > ListBacklogCardsHandler::PER_PAGE;
    }

    public function isNarrowed(): bool
    {
        return null !== $this->search || null !== $this->type || null !== $this->epic;
    }

    /** Only the rank order can take a drag, because only it is the order a drag changes. */
    public function isRanked(): bool
    {
        return BacklogSort::Rank === $this->sort;
    }

    /** @return array{page: int, search?: string, type?: string, epic?: string, sort?: string} */
    public function routeParams(): array
    {
        $params = ['page' => $this->page];

        if (null !== $this->search) {
            $params['search'] = $this->search;
        }

        if (null !== $this->type) {
            $params['type'] = $this->type->value;
        }

        if (null !== $this->epic) {
            $params['epic'] = $this->epic;
        }

        if (!$this->isRanked()) {
            $params['sort'] = $this->sort->value;
        }

        return $params;
    }
}
