<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview;

use PHPUnit\Framework\TestCase;

final class WidgetFileTest extends TestCase
{
    public function test_widget_file_exists_and_is_self_contained(): void
    {
        $path = dirname(__DIR__, 3).'/public/site-review/widget.js';
        self::assertFileExists($path);

        $src = (string) file_get_contents($path);
        self::assertStringContainsString('attachShadow', $src);
        self::assertStringContainsString('data-token', $src);
        self::assertStringContainsString('/api/site-review/comments', $src);
        // The widget saves as the reviewer writes; there is no send step to call.
        self::assertStringNotContainsString('/api/site-review/review/submit', $src);

        // Comments live on the server alone. The launcher's corner is the one
        // thing the widget keeps in the browser, so one write is the budget.
        self::assertStringContainsString("'loupe.site-review.corner'", $src);
        self::assertSame(1, substr_count($src, 'localStorage.setItem'));
    }
}
