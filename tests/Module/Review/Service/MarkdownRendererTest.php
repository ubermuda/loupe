<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Review\Service\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MarkdownRendererTest extends TestCase
{
    public function test_renders_markdown_and_strips_dangerous_html(): void
    {
        $html = new MarkdownRenderer(new NullLogger())->render("# Title\n\nHello <script>alert(1)</script> world\n\n- a\n- b");

        self::assertStringContainsString('<h1 id="heading-title">Title</h1>', $html);
        self::assertStringContainsString('<li>a</li>', $html);
        self::assertStringContainsString('<p>', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('alert(1)', $html);
    }

    public function test_strips_onclick_attributes(): void
    {
        $html = new MarkdownRenderer(new NullLogger())->render('<p onclick="alert(2)">hi</p>');

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

        $html = new MarkdownRenderer(new NullLogger())->render($markdown);

        self::assertStringContainsString('The Very Last Heading', $html);
        self::assertStringContainsString('final-marker-text', $html);
    }

    public function test_strips_javascript_links(): void
    {
        $html = new MarkdownRenderer(new NullLogger())->render('[click me](javascript:alert(1))');

        self::assertStringContainsString('click me', $html);
        self::assertStringNotContainsString('javascript:', $html);
    }

    public function test_gives_every_heading_a_stable_id(): void
    {
        $html = new MarkdownRenderer(new NullLogger())->render("# The Title\n\n## Open Questions\n\n### Résumé & co\n");

        self::assertStringContainsString('<h1 id="heading-the-title">The Title</h1>', $html);
        self::assertStringContainsString('<h2 id="heading-open-questions">Open Questions</h2>', $html);
        self::assertStringContainsString('<h3 id="heading-résumé-co">', $html);
    }

    public function test_repeated_headings_get_distinct_ids(): void
    {
        $html = new MarkdownRenderer(new NullLogger())->render("## Notes\n\n## Notes\n\n## Notes\n");

        self::assertStringContainsString('id="heading-notes"', $html);
        self::assertStringContainsString('id="heading-notes-2"', $html);
        self::assertStringContainsString('id="heading-notes-3"', $html);
    }

    public function test_heading_ids_leave_the_anchor_text_basis_untouched(): void
    {
        // DocumentVersion::plainText() — the basis every comment anchor offset is
        // measured against — is strip_tags() of this HTML. An id lives in an
        // attribute, so it must not reach the text.
        $html = new MarkdownRenderer(new NullLogger())->render("## Open Questions\n\nBody text.\n");

        $plainText = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        self::assertSame("Open Questions\nBody text.\n", $plainText);
    }

    public function test_document_supplied_ids_and_classes_cannot_reach_the_page(): void
    {
        // `composer-error` is a live Turbo stream target on the review page.
        $html = new MarkdownRenderer(new NullLogger())->render('<h2 id="composer-error" class="lp-anchor">Injected</h2>');

        self::assertStringContainsString('<h2 id="heading-injected">Injected</h2>', $html);
        self::assertStringNotContainsString('composer-error', $html);
        self::assertStringNotContainsString('lp-anchor', $html);
    }

    public function test_keeps_the_attributes_the_renderer_itself_emits(): void
    {
        $html = new MarkdownRenderer(new NullLogger())->render(
            "3. three\n4. four\n\n```php\necho 1;\n```\n\n| a | b |\n|:--|--:|\n| 1 | 2 |\n",
        );

        self::assertStringContainsString('<ol start="3">', $html);
        self::assertStringContainsString('<code class="language-php">', $html);
        self::assertStringContainsString('<th align="left">a</th>', $html);
        self::assertStringContainsString('<td align="right">2</td>', $html);
    }

    public function test_keeps_checkboxes(): void
    {
        $html = new MarkdownRenderer(new NullLogger())->render('<input type="checkbox" checked disabled> shipped');

        self::assertStringContainsString('type="checkbox"', $html);
        self::assertStringContainsString('checked', $html);
        self::assertStringContainsString('shipped', $html);
    }

    public function test_an_element_it_does_not_render_still_contributes_its_text(): void
    {
        // Dropping the text instead would move every comment anchor below it.
        $html = new MarkdownRenderer(new NullLogger())->render('<section><figure>captioned</figure></section>');

        self::assertStringContainsString('captioned', $html);
        self::assertStringNotContainsString('<section>', $html);
    }

    public function test_renders_front_matter_as_a_table_above_the_document(): void
    {
        $html = new MarkdownRenderer(new NullLogger())->render(
            "---\ntitle: \"Wave C\"\ndate: 2026-08-02\ntags:\n  - review\n  - markdown\n---\n\n## First Section\n\nBody.\n",
        );

        self::assertStringStartsWith('<table class="lp-front-matter">', $html);
        self::assertStringContainsString('<th scope="row">title</th>', $html);
        self::assertStringContainsString('<td>Wave C</td>', $html);
        // Unquoted YAML dates parse to a timestamp unless PARSE_DATETIME is on.
        self::assertStringContainsString('<td>2026-08-02</td>', $html);
        self::assertStringContainsString('<td>review, markdown</td>', $html);
        // The keys no longer reach the body as prose, and the fences no longer
        // become a rule and a setext heading.
        self::assertStringNotContainsString('<hr />', $html);
        self::assertStringNotContainsString('title: ', $html);
    }

    public function test_only_the_front_matter_table_can_carry_a_class(): void
    {
        // What tells the two apart: a content table is allowed no attributes, so
        // a class on a table is always one the renderer computed.
        $html = new MarkdownRenderer(new NullLogger())->render(
            "---\ntitle: Real\n---\n\n<table class=\"lp-front-matter\"><tr><td>forged</td></tr></table>\n",
        );

        self::assertSame(1, substr_count($html, 'lp-front-matter'));
        self::assertStringContainsString('forged', $html);
    }

    public function test_front_matter_that_is_not_a_key_value_map_still_renders_its_text(): void
    {
        // The extension removes the block from the body whether or not it can be
        // tabulated, so an unparseable or scalar block has to be rendered again
        // without it — otherwise the text vanishes from the page.
        $malformed = new MarkdownRenderer(new NullLogger())->render("---\ntitle: \"unclosed\n  bad: [1, 2\n---\n\nBody.\n");
        $scalar = new MarkdownRenderer(new NullLogger())->render("---\njust a string\n---\n\nBody.\n");

        self::assertStringContainsString('unclosed', $malformed);
        self::assertStringNotContainsString('lp-front-matter', $malformed);
        self::assertStringContainsString('just a string', $scalar);
        self::assertStringNotContainsString('lp-front-matter', $scalar);
    }

    public function test_renders_html_comments_as_visible_annotations(): void
    {
        $html = new MarkdownRenderer(new NullLogger())->render(
            "Before.\n\n<!-- TODO: link the skeleton repo -->\n\nMid <!-- inline note --> sentence.\n",
        );

        self::assertStringContainsString(
            '<aside role="note" class="lp-doc-note">TODO: link the skeleton repo</aside>',
            $html,
        );
        self::assertStringContainsString(
            '<span class="lp-doc-note lp-doc-note--inline">inline note</span>',
            $html,
        );
    }

    public function test_a_comment_inside_a_code_fence_stays_literal(): void
    {
        // Block-vs-inline comes from the parser's node type, so fenced content —
        // a FencedCode node — never reaches the comment renderer at all.
        $html = new MarkdownRenderer(new NullLogger())->render("```\n<!-- not an annotation -->\n```\n");

        self::assertStringContainsString('&lt;!-- not an annotation --&gt;', $html);
        self::assertStringNotContainsString('lp-doc-note', $html);
    }

    public function test_a_comment_cannot_smuggle_markup_into_the_annotation(): void
    {
        $html = new MarkdownRenderer(new NullLogger())->render('<!-- <script>alert(1)</script> -->');

        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    public function test_the_comment_markers_never_reach_the_output(): void
    {
        // The markers carry a comment's text across the sanitizer and must all be
        // consumed again. A marker that survives prints a raw nonce on the page,
        // and — because it is random per instance — makes the same source render
        // differently every time, so the re-render command rewrites every
        // version forever. The long document is the case that used to do it: the
        // sanitizer truncated at 1 000 000 bytes, and the cut landed mid-marker.
        $long = str_repeat("Paragraph text that pads the document out.\n\n", 20_000)
            ."<!-- a marker past the old truncation point -->\n\nTail.\n";
        self::assertGreaterThan(500_000, \strlen($long));

        $first = new MarkdownRenderer(new NullLogger())->render($long);
        $second = new MarkdownRenderer(new NullLogger())->render($long);

        self::assertStringNotContainsString('loupe-note', $first);
        self::assertSame($first, $second, 'two renderers must agree on the same source');
        self::assertStringContainsString('a marker past the old truncation point', $first);
        self::assertStringContainsString('Tail.', $first);
    }

    public function test_two_comments_on_one_line_become_two_annotations(): void
    {
        // `<!-- a --><!-- b -->` is a single HtmlBlock, and a greedy match reads
        // it as one comment whose text contains the markup between them.
        $html = new MarkdownRenderer(new NullLogger())->render("<!-- first --><!-- second -->\n");

        self::assertSame(2, substr_count($html, 'lp-doc-note'));
        self::assertStringContainsString('>first</aside>', $html);
        self::assertStringContainsString('>second</aside>', $html);
        self::assertStringNotContainsString('--&gt;', $html);
    }

    public function test_a_block_with_trailing_markup_is_left_alone(): void
    {
        $html = new MarkdownRenderer(new NullLogger())->render("<!-- note --> trailing -->\n");

        self::assertStringNotContainsString('lp-doc-note', $html);
    }

    /**
     * The empty-container seed is the case a leaf-only budget misses entirely:
     * it flattens to no text, so charging scalars alone lets the whole expanded
     * tree be walked for free. Both seeds must terminate.
     *
     * @return iterable<string, array{string}>
     */
    public static function aliasBombSeeds(): iterable
    {
        // Each shape stresses a different axis, because three separate rounds of
        // this bug were each an expansion the previous bound could not see:
        // values, then container visits, then nested mapping keys. Listing shapes
        // is still enumeration, so the assertion below is the invariant rather
        // than a per-shape expectation — a new shape only needs adding here.
        $longKey = str_repeat('k', 200);

        yield 'deep scalar aliases' => ['["lol"]'];
        yield 'empty containers' => ['[]'];
        yield 'long repeated keys' => [sprintf('{%s: x}', $longKey)];
        yield 'wide fan-out' => ['['.implode(', ', array_fill(0, 40, '"x"')).']'];
        yield 'mixed containers and scalars' => ['[[], "x", {a: []}, [[]]]'];
        yield 'nested long keys with no leaf text' => [sprintf('{%s: {}}', $longKey)];
        yield 'nested past the depth ceiling' => [str_repeat('[', 12).'"x"'.str_repeat(']', 12)];
    }

    /**
     * The invariant, not a per-bomb expectation: for any front matter inside the
     * input cap, rendering terminates, emits a bounded amount of HTML, and does
     * not tabulate. A fixture proves one bomb is handled; this is the property
     * that has to hold for the ones nobody has constructed yet.
     */
    #[DataProvider('aliasBombSeeds')]
    public function test_front_matter_expansion_is_bounded_whatever_its_shape(string $seed): void
    {
        $yaml = "---\na0: &a0 {$seed}\n";
        for ($level = 1; $level <= 8; ++$level) {
            $references = implode(', ', array_fill(0, 9, sprintf('*a%d', $level - 1)));
            $yaml .= sprintf("a%d: &a%d [%s]\n", $level, $level, $references);
        }
        $markdown = $yaml."---\n\nBody.\n";
        // Every shape is far inside DocumentCreateTool::MAX_MARKDOWN_BYTES, which
        // is what makes the source cap useless against exponential growth.
        self::assertLessThan(5_000, \strlen($markdown));

        $started = microtime(true);
        $html = new MarkdownRenderer(new NullLogger())->render($markdown);
        $elapsed = microtime(true) - $started;

        self::assertStringNotContainsString('lp-front-matter', $html);
        self::assertLessThan(200_000, \strlen($html), 'rendered output must stay bounded');
        self::assertLessThan(5.0, $elapsed, 'rendering must terminate promptly');
        self::assertStringContainsString('Body.', $html);
    }

    public function test_a_yaml_merge_key_bomb_is_bounded(): void
    {
        // `<<:` duplicates a whole mapping rather than referencing one value, so
        // it multiplies keys where an alias multiplies values.
        $yaml = "---\na0: &a0 {".str_repeat('k', 200).": x}\n";
        for ($level = 1; $level <= 6; ++$level) {
            $yaml .= sprintf("a%d: &a%d\n", $level, $level);
            for ($copy = 0; $copy < 9; ++$copy) {
                $yaml .= sprintf("  m%d:\n    <<: *a%d\n", $copy, $level - 1);
            }
        }

        $started = microtime(true);
        $html = new MarkdownRenderer(new NullLogger())->render($yaml."---\n\nBody.\n");

        self::assertLessThan(200_000, \strlen($html));
        self::assertLessThan(5.0, microtime(true) - $started);
        self::assertStringContainsString('Body.', $html);
    }

    public function test_nesting_past_the_depth_ceiling_falls_back_rather_than_truncating(): void
    {
        // All three ceilings have to behave the same way: discard the table and
        // render the block as text. Depth used to stop the traversal without
        // latching, so the table was kept and stored with the deep value
        // rendered empty — a reviewer sees a key the document filled in, shown
        // blank, with nothing saying anything was dropped.
        $deep = str_repeat('[', 18).'"buried"'.str_repeat(']', 18);

        $html = new MarkdownRenderer(new NullLogger())->render("---\nkey: {$deep}\n---\n\nBody.\n");

        self::assertStringNotContainsString('lp-front-matter', $html);
        self::assertStringContainsString('buried', $html);
    }

    public function test_a_top_level_sequence_is_not_tabulated(): void
    {
        // A sequence is an array with no keys, so tabulating it would invent 0
        // and 1 and show them as if the document had written them.
        $html = new MarkdownRenderer(new NullLogger())->render("---\n- one\n- two\n---\n\nBody.\n");

        self::assertStringNotContainsString('lp-front-matter', $html);
        self::assertStringContainsString('one', $html);
        self::assertStringContainsString('two', $html);
    }

    public function test_a_large_but_legitimate_front_matter_still_tabulates(): void
    {
        // Guards the other side of the budget: the bound must sit far above what
        // a real document carries, or it silently demotes ordinary front matter.
        $yaml = "---\n";
        for ($key = 0; $key < 40; ++$key) {
            $yaml .= sprintf("key%d: %s\n", $key, str_repeat('word ', 20));
        }
        $yaml .= 'tags: ['.implode(', ', array_map(static fn (int $i): string => "tag{$i}", range(1, 50)))."]\n";

        $html = new MarkdownRenderer(new NullLogger())->render($yaml."---\n\nBody.\n");

        self::assertStringContainsString('lp-front-matter', $html);
        self::assertStringContainsString('tag50', $html);
    }

    public function test_an_empty_comment_renders_nothing(): void
    {
        $html = new MarkdownRenderer(new NullLogger())->render("Before.\n\n<!--   -->\n\nAfter.\n");

        self::assertStringNotContainsString('lp-doc-note', $html);
    }

    public function test_front_matter_and_comments_change_the_anchor_text_basis(): void
    {
        // Both features move DocumentVersion::plainText() by construction: the
        // front-matter keys stop arriving as prose and start arriving as table
        // cells, and a comment's text goes from contributing nothing to
        // contributing itself. Every stored version has to be re-rendered.
        $html = new MarkdownRenderer(new NullLogger())->render("---\ntitle: T\n---\n\nA.\n\n<!-- note -->\n\nB.\n");

        $plainText = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        self::assertSame("\n\n\ntitle\nT\n\n\n\nA.\nnote\nB.\n", $plainText);
    }
}
