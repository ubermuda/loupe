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
        self::assertStringContainsString('data-context', $src);
        // The boot load is the only place the instance can tell the widget what
        // the marker resolves to, so the context has to travel with it.
        // pageMarker() rather than CONTEXT: an SPA swap replaces the script
        // tag, and the marker on the new page is the one that counts.
        self::assertStringContainsString('encodeURIComponent(pageMarker())', $src);
        // The marker the page proposes is a default, not a verdict: the picker
        // lets a reviewer swap or drop it, and the save reads the live value.
        // Empty until the server confirms the page's marker resolves, so a
        // comment saved before that answer carries nothing it cannot show.
        self::assertStringContainsString("let currentContext = '';", $src);
        self::assertStringContainsString('/api/board/cards', $src);
        // The save reads the live marker, never the page's attribute. This
        // assertion replaces one that required the opposite, which was correct
        // while the page's attribute was the only source.
        self::assertStringNotContainsString('{ context: CONTEXT }', $src);
        // An ordinary deployment renders no attribute, a misconfigured one
        // renders an empty attribute, and a reviewer may detach. None of the
        // three may reach the API as a value, so the key is sent only when
        // there is something in it.
        self::assertStringContainsString('...(currentContext ? { context: currentContext } : {})', $src);
        self::assertStringContainsString('/api/site-review/comments', $src);
        // The widget saves as the reviewer writes; there is no send step to call.
        self::assertStringNotContainsString('/api/site-review/review/submit', $src);

        // Comments live on the server alone. The launcher's corner is the one
        // thing the widget keeps in the browser, so one write is the budget.
        self::assertStringContainsString("'loupe.site-review.corner'", $src);
        self::assertSame(1, substr_count($src, 'localStorage.setItem'));
    }
}
