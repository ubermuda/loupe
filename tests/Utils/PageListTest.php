<?php

declare(strict_types=1);

namespace App\Tests\Utils;

use App\Utils\PageList;
use PHPUnit\Framework\TestCase;

final class PageListTest extends TestCase
{
    public function test_build_lists_every_page_when_total_is_small(): void
    {
        self::assertSame([1, 2, 3], PageList::build(1, 3));
    }

    public function test_build_elides_pages_far_from_current(): void
    {
        self::assertSame([1, null, 8, 9, 10, 11, 12, null, 20], PageList::build(10, 20));
    }

    public function test_build_has_no_ellipsis_when_the_gap_is_a_single_page(): void
    {
        // Gap of exactly one page between the anchor and the current-page window
        // must show the page itself, not an ellipsis standing in for one page.
        self::assertSame([1, 2, 3, 4, 5], PageList::build(4, 5));
    }

    public function test_clamped_page_is_null_when_page_is_in_range(): void
    {
        self::assertNull(PageList::clampedPage(2, 40, 20));
    }

    public function test_clamped_page_is_null_when_total_is_zero(): void
    {
        self::assertNull(PageList::clampedPage(5, 0, 20));
    }

    public function test_clamped_page_returns_the_last_page_when_out_of_range(): void
    {
        self::assertSame(2, PageList::clampedPage(5, 40, 20));
    }
}
