<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\View;

use App\Module\Bridge\ValueObject\CostGroup;
use App\Module\Bridge\ValueObject\CostRange;
use App\Module\Bridge\ValueObject\CostSplit;
use App\Module\Bridge\View\WorkerRunCostQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class WorkerRunCostQueryTest extends TestCase
{
    /** @return iterable<string, array{CostRange, CostGroup}> */
    public static function defaultGroups(): iterable
    {
        yield 'thirty days' => [CostRange::ThirtyDays, CostGroup::Day];
        yield 'ninety days' => [CostRange::NinetyDays, CostGroup::Week];
        yield 'all time' => [CostRange::AllTime, CostGroup::Month];
    }

    #[DataProvider('defaultGroups')]
    public function test_the_group_follows_the_range_by_default(CostRange $range, CostGroup $group): void
    {
        $query = new WorkerRunCostQuery(range: $range);

        self::assertSame($group, $query->effectiveGroup());
        self::assertArrayNotHasKey('group', $query->routeParams());
    }

    public function test_an_explicit_group_overrides_the_range_and_rides_in_the_url(): void
    {
        $query = WorkerRunCostQuery::fromQuery(Request::create('/', Request::METHOD_GET, ['range' => 'thirty-days', 'group' => 'month', 'split' => 'rule'])->query);

        self::assertSame(CostGroup::Month, $query->effectiveGroup());
        self::assertSame(['range' => 'thirty-days', 'split' => 'rule', 'group' => 'month'], $query->routeParams());
    }

    public function test_a_group_equal_to_the_default_keeps_the_bare_url(): void
    {
        self::assertSame([], new WorkerRunCostQuery()->withGroup(CostGroup::Week)->routeParams());
    }

    public function test_a_new_range_drops_the_explicit_group(): void
    {
        $query = new WorkerRunCostQuery(split: CostSplit::Model, group: CostGroup::Day)->withRange(CostRange::AllTime);

        self::assertNull($query->group);
        self::assertSame(CostGroup::Month, $query->effectiveGroup());
        self::assertSame(['range' => 'all-time', 'split' => 'model'], $query->routeParams());
    }

    public function test_an_unknown_group_falls_back_to_the_default(): void
    {
        $query = WorkerRunCostQuery::fromQuery(Request::create('/', Request::METHOD_GET, ['group' => 'fortnight'])->query);

        self::assertNull($query->group);
        self::assertSame(CostGroup::Week, $query->effectiveGroup());
    }
}
