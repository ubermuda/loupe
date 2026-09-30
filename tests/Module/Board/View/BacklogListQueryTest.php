<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\View;

use App\Module\Board\Entity\CardType;
use App\Module\Board\View\BacklogDirection;
use App\Module\Board\View\BacklogListQuery;
use App\Module\Board\View\BacklogSort;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;

final class BacklogListQueryTest extends TestCase
{
    public function test_a_bare_query_reads_newest_first(): void
    {
        $query = BacklogListQuery::fromQuery(self::query([]));

        self::assertSame(BacklogSort::Created, $query->sort);
        self::assertSame(BacklogDirection::Desc, $query->dir);
    }

    public function test_the_query_reads_the_sort_and_the_direction(): void
    {
        $query = BacklogListQuery::fromQuery(self::query(['sort' => 'epic', 'dir' => 'asc']));

        self::assertSame(BacklogSort::Epic, $query->sort);
        self::assertSame(BacklogDirection::Asc, $query->dir);
    }

    public function test_an_unknown_sort_or_direction_reads_as_the_default(): void
    {
        $query = BacklogListQuery::fromQuery(self::query(['sort' => 'newest', 'dir' => 'sideways']));

        self::assertSame(BacklogSort::Created, $query->sort);
        self::assertSame(BacklogDirection::Desc, $query->dir);
    }

    public function test_the_route_params_omit_the_default_order(): void
    {
        self::assertSame(['page' => 1], new BacklogListQuery()->routeParams());
    }

    public function test_the_route_params_carry_both_keys_of_any_other_order(): void
    {
        self::assertSame(
            ['page' => 2, 'type' => 'bug', 'sort' => 'created', 'dir' => 'asc'],
            new BacklogListQuery(page: 2, type: CardType::Bug, dir: BacklogDirection::Asc)->routeParams(),
        );
        self::assertSame(
            ['page' => 1, 'sort' => 'type', 'dir' => 'desc'],
            new BacklogListQuery(sort: BacklogSort::Type)->routeParams(),
        );
    }

    public function test_a_header_link_on_the_current_column_reverses_it_and_starts_at_page_one(): void
    {
        $query = new BacklogListQuery(page: 3, search: 'otter');

        self::assertSame(['page' => 1, 'search' => 'otter', 'sort' => 'created', 'dir' => 'asc'], $query->sortParams(BacklogSort::Created));
        self::assertSame(
            ['page' => 1],
            new BacklogListQuery(dir: BacklogDirection::Asc)->sortParams(BacklogSort::Created),
        );
    }

    public function test_a_header_link_on_another_column_starts_in_its_first_direction(): void
    {
        $query = new BacklogListQuery();

        self::assertSame(['page' => 1, 'sort' => 'type', 'dir' => 'asc'], $query->sortParams(BacklogSort::Type));
        self::assertSame(['page' => 1, 'sort' => 'epic', 'dir' => 'asc'], $query->sortParams(BacklogSort::Epic));
        self::assertSame(
            ['page' => 1],
            new BacklogListQuery(sort: BacklogSort::Type)->sortParams(BacklogSort::Created),
        );
    }

    public function test_the_aria_sort_names_the_direction_of_the_current_column_only(): void
    {
        $query = new BacklogListQuery(sort: BacklogSort::Epic, dir: BacklogDirection::Asc);

        self::assertSame('ascending', $query->ariaSort(BacklogSort::Epic));
        self::assertSame('none', $query->ariaSort(BacklogSort::Type));
        self::assertSame('descending', new BacklogListQuery()->ariaSort(BacklogSort::Created));
    }

    /**
     * @param array<string, string> $values
     *
     * @return InputBag<string>
     */
    private static function query(array $values): InputBag
    {
        return new Request($values)->query;
    }
}
