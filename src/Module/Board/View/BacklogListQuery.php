<?php

declare(strict_types=1);

namespace App\Module\Board\View;

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
        /** A type key, as typed. The controller drops a key the project does not declare. */
        public ?string $type = null,
        /** Null is any epic, NO_EPIC is no epic, and a card id is that epic. */
        public ?string $epic = null,
        public BacklogSort $sort = BacklogSort::Created,
        public BacklogDirection $dir = BacklogDirection::Desc,
    ) {
    }

    /** @param InputBag<string> $query */
    public static function fromQuery(InputBag $query): self
    {
        $search = trim($query->getString('search'));
        $epic = strtolower(trim($query->getString('epic')));
        $type = trim($query->getString('type'));

        return new self(
            page: max(1, $query->getInt('page', 1)),
            search: '' === $search ? null : $search,
            type: '' === $type ? null : $type,
            epic: self::NO_EPIC === $epic || Uuid::isValid($epic) ? $epic : null,
            sort: BacklogSort::tryFrom($query->getString('sort')) ?? BacklogSort::Created,
            dir: BacklogDirection::tryFrom($query->getString('dir')) ?? BacklogDirection::Desc,
        );
    }

    public function withPage(int $page): self
    {
        return clone ($this, ['page' => $page]);
    }

    public function withoutType(): self
    {
        return clone ($this, ['type' => null]);
    }

    /** Whether a filter narrows the list, which separates an empty Backlog from no match. */
    public function isNarrowed(): bool
    {
        return null !== $this->search || null !== $this->type || null !== $this->epic;
    }

    /**
     * The params of a column header link: the current column reverses, and
     * another column starts in its first direction. The list starts at page one.
     *
     * @return array{page: int, search?: string, type?: string, epic?: string, sort?: string, dir?: string}
     */
    public function sortParams(BacklogSort $column): array
    {
        $dir = $column === $this->sort ? $this->dir->reversed() : $column->firstDirection();

        return (clone ($this, ['page' => 1, 'sort' => $column, 'dir' => $dir]))->routeParams();
    }

    /** The aria-sort value of a column header. */
    public function ariaSort(BacklogSort $column): string
    {
        if ($column !== $this->sort) {
            return 'none';
        }

        return BacklogDirection::Asc === $this->dir ? 'ascending' : 'descending';
    }

    /** @return array{page: int, search?: string, type?: string, epic?: string, sort?: string, dir?: string} */
    public function routeParams(): array
    {
        $params = ['page' => $this->page];

        if (null !== $this->search) {
            $params['search'] = $this->search;
        }

        if (null !== $this->type) {
            $params['type'] = $this->type;
        }

        if (null !== $this->epic) {
            $params['epic'] = $this->epic;
        }

        if (BacklogSort::Created !== $this->sort || BacklogDirection::Desc !== $this->dir) {
            $params['sort'] = $this->sort->value;
            $params['dir'] = $this->dir->value;
        }

        return $params;
    }
}
