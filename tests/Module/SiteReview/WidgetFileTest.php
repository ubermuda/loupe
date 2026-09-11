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
        // Read once per request and carried through the answer, never re-read
        // when it lands: an SPA swap can change it in between.
        self::assertStringContainsString('const asked = pageMarker();', $src);
        self::assertStringContainsString('encodeURIComponent(asked)', $src);
        // The marker the page proposes is a default, not a verdict: the picker
        // lets a reviewer swap or drop it, and the save reads the live value.
        // Empty until the server confirms the page's marker resolves, so a
        // comment saved before that answer carries nothing it cannot show.
        self::assertStringContainsString("let currentContext = '';", $src);
        self::assertStringContainsString('/api/board/cards', $src);
        // Raw hex is correct in this file, which carries its own palette. It is
        // wrong in the picker, which sits inside a themed shadow root: a
        // hardcoded white panel appeared inside the dark widget.
        preg_match_all('/^\s*\.lp-(picker|context)[^{]*\{[^}]*\}/m', $src, $rules);
        self::assertNotEmpty($rules[0]);
        foreach ($rules[0] as $rule) {
            self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $rule, $rule);
        }
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

        // The launcher says when the backend is a local build, so a reviewer
        // sees that the comments land in a preview instance. The origin the
        // script already computes is the only source, so production cannot
        // switch it on. The label is a span, never a control.
        self::assertStringContainsString('const LOCAL =', $src);
        self::assertStringContainsString('/(^|\\.)localhost$/.test(BACKEND_HOST)', $src);
        self::assertStringContainsString('/^127\\./.test(BACKEND_HOST)', $src);
        self::assertStringContainsString('/\\.local$/.test(BACKEND_HOST)', $src);
        // A false LOCAL emits no node at all, so the launcher keeps its width.
        self::assertStringContainsString(
            '${LOCAL ? \'<span class="lp-local" id="lp-local" data-tip="Comments save to this local build">Local</span>\' : \'\'}',
            $src,
        );

        // Comments live on the server alone. The launcher's corner is the one
        // thing the widget keeps in the browser, so one write is the budget.
        self::assertStringContainsString("'loupe.site-review.corner'", $src);
        self::assertSame(1, substr_count($src, 'localStorage.setItem'));
    }
}
