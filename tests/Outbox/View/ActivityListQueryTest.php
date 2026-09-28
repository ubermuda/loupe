<?php

declare(strict_types=1);

namespace App\Tests\Outbox\View;

use App\Outbox\ActivityFamily;
use App\Outbox\View\ActivityListQuery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ActivityListQueryTest extends TestCase
{
    public function test_it_reads_the_page_the_search_and_the_family(): void
    {
        $query = self::read(['page' => '3', 'search' => '  merged pr  ', 'family' => 'pull-request']);

        self::assertSame(3, $query->page);
        self::assertSame('merged pr', $query->search);
        self::assertSame(ActivityFamily::PullRequest, $query->family);
    }

    public function test_an_empty_query_is_the_first_unnarrowed_page(): void
    {
        $query = self::read([]);

        self::assertSame(1, $query->page);
        self::assertNull($query->search);
        self::assertNull($query->family);
        self::assertFalse($query->isNarrowed());
        self::assertSame(['page' => 1], $query->routeParams());
    }

    public function test_an_unknown_family_a_blank_search_and_a_bad_page_are_dropped(): void
    {
        $query = self::read(['page' => '-4', 'search' => '   ', 'family' => 'pull_request']);

        self::assertSame(1, $query->page);
        self::assertNull($query->search);
        self::assertNull($query->family);
    }

    public function test_either_filter_narrows_the_list(): void
    {
        self::assertTrue(new ActivityListQuery(search: 'moved')->isNarrowed());
        self::assertTrue(new ActivityListQuery(family: ActivityFamily::Board)->isNarrowed());
    }

    public function test_route_params_carry_only_the_filters_that_are_on(): void
    {
        self::assertSame(
            ['page' => 2, 'search' => 'moved', 'family' => 'site-review'],
            new ActivityListQuery(2, 'moved', ActivityFamily::SiteReview)->routeParams(),
        );
        self::assertSame(['page' => 2, 'family' => 'board'], new ActivityListQuery(2, family: ActivityFamily::Board)->routeParams());
    }

    public function test_with_page_keeps_the_filters(): void
    {
        $query = new ActivityListQuery(9, 'moved', ActivityFamily::Board)->withPage(4);

        self::assertSame(['page' => 4, 'search' => 'moved', 'family' => 'board'], $query->routeParams());
    }

    /** @param array<string, string> $params */
    private static function read(array $params): ActivityListQuery
    {
        return ActivityListQuery::fromQuery(Request::create('/', Request::METHOD_GET, $params)->query);
    }
}
