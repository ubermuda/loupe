<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Review\Service\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

final class MarkdownRendererTest extends TestCase
{
    public function test_renders_markdown_and_strips_dangerous_html(): void
    {
        $html = new MarkdownRenderer()->render("# Title\n\nHello <script>alert(1)</script> world\n\n- a\n- b");

        self::assertStringContainsString('<h1 id="heading-title">Title</h1>', $html);
        self::assertStringContainsString('<li>a</li>', $html);
        self::assertStringContainsString('<p>', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('alert(1)', $html);
    }

    public function test_strips_onclick_attributes(): void
    {
        $html = new MarkdownRenderer()->render('<p onclick="alert(2)">hi</p>');

        self::assertStringContainsString('hi', $html);
        self::assertStringNotContainsString('onclick', $html);
    }

    public function test_renders_documents_larger_than_the_sanitizer_default_limit(): void
    {
        // The sanitizer's default 20 000-byte input cap silently truncated long
        // documents; a >20KB document must keep its final section.
        $section = "## Section\n\nSome paragraph text that pads the document out.\n\n";
        $markdown = str_repeat($section, 500)."## The Very Last Heading\n\nfinal-marker-text\n";
        self::assertGreaterThan(20_000, strlen($markdown));

        $html = new MarkdownRenderer()->render($markdown);

        self::assertStringContainsString('The Very Last Heading', $html);
        self::assertStringContainsString('final-marker-text', $html);
    }

    public function test_strips_javascript_links(): void
    {
        $html = new MarkdownRenderer()->render('[click me](javascript:alert(1))');

        self::assertStringContainsString('click me', $html);
        self::assertStringNotContainsString('javascript:', $html);
    }

    public function test_gives_every_heading_a_stable_id(): void
    {
        $html = new MarkdownRenderer()->render("# The Title\n\n## Open Questions\n\n### Résumé & co\n");

        self::assertStringContainsString('<h1 id="heading-the-title">The Title</h1>', $html);
        self::assertStringContainsString('<h2 id="heading-open-questions">Open Questions</h2>', $html);
        self::assertStringContainsString('<h3 id="heading-résumé-co">', $html);
    }

    public function test_repeated_headings_get_distinct_ids(): void
    {
        $html = new MarkdownRenderer()->render("## Notes\n\n## Notes\n\n## Notes\n");

        self::assertStringContainsString('id="heading-notes"', $html);
        self::assertStringContainsString('id="heading-notes-2"', $html);
        self::assertStringContainsString('id="heading-notes-3"', $html);
    }

    public function test_heading_ids_leave_the_anchor_text_basis_untouched(): void
    {
        // DocumentVersion::plainText() — the basis every comment anchor offset is
        // measured against — is strip_tags() of this HTML. An id lives in an
        // attribute, so it must not reach the text.
        $html = new MarkdownRenderer()->render("## Open Questions\n\nBody text.\n");

        $plainText = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        self::assertSame("Open Questions\nBody text.\n", $plainText);
    }

    public function test_document_supplied_ids_and_classes_cannot_reach_the_page(): void
    {
        // `composer-error` is a live Turbo stream target on the review page.
        $html = new MarkdownRenderer()->render('<h2 id="composer-error" class="lp-anchor">Injected</h2>');

        self::assertStringContainsString('<h2 id="heading-injected">Injected</h2>', $html);
        self::assertStringNotContainsString('composer-error', $html);
        self::assertStringNotContainsString('lp-anchor', $html);
    }

    public function test_keeps_the_attributes_the_renderer_itself_emits(): void
    {
        $html = new MarkdownRenderer()->render(
            "3. three\n4. four\n\n```php\necho 1;\n```\n\n| a | b |\n|:--|--:|\n| 1 | 2 |\n",
        );

        self::assertStringContainsString('<ol start="3">', $html);
        self::assertStringContainsString('<code class="language-php">', $html);
        self::assertStringContainsString('<th align="left">a</th>', $html);
        self::assertStringContainsString('<td align="right">2</td>', $html);
    }

    public function test_a_document_cannot_render_a_form_control(): void
    {
        // No form control has a producer: nothing in the pipeline emits one, and
        // Markdown's `- [ ]` renders as literal text because TaskListExtension is
        // not registered. `input` is void, so dropping it takes no text with it and
        // the surrounding words keep their offsets in plainText().
        $html = new MarkdownRenderer()->render('<input type="password" name="pw" checked> shipped');

        self::assertStringNotContainsString('<input', $html);
        self::assertStringContainsString('shipped', $html);
    }

    public function test_a_code_class_may_only_ever_be_a_language_token(): void
    {
        // <code> is an ordinary box and every component class is compiled
        // unconditionally, so an unconstrained value is a full-viewport overlay.
        $renderer = new MarkdownRenderer();

        self::assertStringContainsString(
            '<code class="language-php">',
            $renderer->render("```php\necho 1;\n```"),
        );
        self::assertSame(
            '<p><code>overlay</code></p>',
            trim($renderer->render('<code class="fixed w-full min-h-screen bg-white z-10 block">overlay</code>')),
        );
        self::assertSame(
            '<p><code>overlay</code></p>',
            trim($renderer->render('<code class="lp-review lp-review-layout">overlay</code>')),
        );
        // A language token smuggling a second class alongside it is refused whole.
        self::assertSame(
            '<p><code>overlay</code></p>',
            trim($renderer->render('<code class="language-php lp-review-layout">overlay</code>')),
        );
    }

    public function test_repeated_headings_stay_linear_to_de_duplicate(): void
    {
        // Quadratic de-duplication made a document of many same-named headings pin a
        // worker for minutes; rendering is synchronous inside the MCP request.
        $renderer = new MarkdownRenderer();

        $start = microtime(true);
        $html = $renderer->render(str_repeat("## Same\n\n", 20_000));
        $elapsed = microtime(true) - $start;

        self::assertStringContainsString('id="heading-same-20000"', $html);
        self::assertLessThan(5.0, $elapsed);
    }

    public function test_an_element_it_does_not_render_still_contributes_its_text(): void
    {
        // Dropping the text instead would move every comment anchor below it.
        $html = new MarkdownRenderer()->render('<section><figure>captioned</figure></section>');

        self::assertStringContainsString('captioned', $html);
        self::assertStringNotContainsString('<section>', $html);
    }
}
