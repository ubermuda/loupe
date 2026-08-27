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

    public function test_keeps_the_href_on_a_documents_own_in_page_link(): void
    {
        $html = new MarkdownRenderer(new NullLogger())->render("# Intro\n\n[jump](#heading-intro)");

        // The renderer mints heading-<slug> ids itself, so an author has every
        // reason to link to one; the sanitizer used to strip the href and leave
        // dead text with no error anywhere.
        self::assertStringContainsString('href="#heading-intro"', $html);
    }

    public function test_keeps_the_href_on_a_same_origin_path(): void
    {
        $html = new MarkdownRenderer(new NullLogger())->render('[the project](/projects/abc)');

        self::assertStringContainsString('href="/projects/abc"', $html);
    }

    public function test_strips_onclick_attributes(): void
    {
        $html = new MarkdownRenderer(new NullLogger())->render('<p onclick="alert(2)">hi</p>');

        self::assertStringContainsString('hi', $html);
        self::assertStringNotContainsString('onclick', $html);
    }

    /**
     * CommonMark's own `allow_unsafe_links` filter missed schemes obfuscated with
     * embedded tabs or newlines before 2.9.0. The sanitizer's scheme allow-list
     * runs after it and caught them anyway, which is the property under test:
     * document markup is agent-supplied, so neither layer may be the only one.
     */
    #[DataProvider('obfuscatedJavascriptUrls')]
    public function test_an_obfuscated_javascript_url_never_survives_as_an_attribute(string $markdown): void
    {
        $html = new MarkdownRenderer(new NullLogger())->render($markdown);

        self::assertDoesNotMatchRegularExpression('~(href|src)\s*=~i', $html);
    }

    /** @return iterable<string, array{string}> */
    public static function obfuscatedJavascriptUrls(): iterable
    {
        yield 'plain scheme in markdown' => ['[click](javascript:alert(1))'];
        yield 'tab inside the scheme' => ["<a href=\"java\tscript:alert(1)\">click</a>"];
        yield 'newline inside the scheme' => ["<a href=\"java\nscript:alert(1)\">click</a>"];
        yield 'leading control character' => ["<a href=\"\x01javascript:alert(1)\">click</a>"];
        yield 'obfuscated image source' => ["<img src=\"java\tscript:alert(1)\">"];
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

    public function test_an_illustrated_heading_is_slugged_from_the_image_alt_text(): void
    {
        // strip_tags() alone reduces an image-only heading to nothing, which used to
        // give every one of them the same generic id.
        $renderer = new MarkdownRenderer(new NullLogger());

        self::assertStringContainsString(
            '<h2 id="heading-diagram">',
            $renderer->render("## ![Diagram](architecture.png)\n"),
        );
        // Mixed: one space between the two parts, neither doubled nor run together.
        self::assertStringContainsString(
            '<h2 id="heading-request-flow-diagram">',
            $renderer->render("## Request flow ![Diagram](architecture.png)\n"),
        );
    }

    public function test_a_heading_with_nothing_to_label_it_still_gets_a_distinct_id(): void
    {
        // An image with no alt leaves no text, and no filename either — the sanitizer
        // drops a relative src. The id has to exist regardless, so links resolve.
        $html = new MarkdownRenderer(new NullLogger())->render("## ![](one.png)\n\n## ![](two.png)\n");

        self::assertStringContainsString('<h2 id="heading-section">', $html);
        self::assertStringContainsString('<h2 id="heading-section-2">', $html);
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

    public function test_a_document_cannot_render_a_form_control(): void
    {
        // No form control has a producer: nothing in the pipeline emits one, and
        // Markdown's `- [ ]` renders as literal text because TaskListExtension is
        // not registered. `input` is void, so dropping it takes no text with it and
        // the surrounding words keep their offsets in plainText().
        $html = new MarkdownRenderer(new NullLogger())->render('<input type="password" name="pw" checked> shipped');

        self::assertStringNotContainsString('<input', $html);
        self::assertStringContainsString('shipped', $html);
    }

    public function test_a_code_class_may_only_ever_be_a_language_token(): void
    {
        // <code> is an ordinary box and every component class is compiled
        // unconditionally, so an unconstrained value is a full-viewport overlay.
        $renderer = new MarkdownRenderer(new NullLogger());

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
        $renderer = new MarkdownRenderer(new NullLogger());

        $start = microtime(true);
        $html = $renderer->render(str_repeat("## Same\n\n", 20_000));
        $elapsed = microtime(true) - $start;

        self::assertStringContainsString('id="heading-same-20000"', $html);
        self::assertLessThan(5.0, $elapsed);
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
        // Every marker must be consumed again: a survivor prints a raw nonce and,
        // being random per instance, makes the same source render differently
        // every time. The long document is the case that did it — the sanitizer's
        // cut landed mid-marker.
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

    public function test_a_failed_annotation_pass_throws_instead_of_shipping_markers(): void
    {
        // Returning the subject unchanged would leave the markers in it,
        // printing a raw nonce and making the same source render differently each
        // time. Driven through the private method because starving the backtrack
        // limit breaks the comment renderer's own patterns first, so no marker is
        // ever emitted.
        $renderer = new MarkdownRenderer(new NullLogger());
        $reflection = new \ReflectionClass($renderer);
        $open = $reflection->getProperty('noteBlockOpen')->getValue($renderer);
        $close = $reflection->getProperty('noteClose')->getValue($renderer);
        self::assertIsString($open);
        self::assertIsString($close);

        $subject = '<p>'.$open.str_repeat('a', 2_000).$close.'</p>';
        $backtrackLimit = \ini_get('pcre.backtrack_limit');
        \ini_set('pcre.backtrack_limit', '1');

        // Not a try/catch: PHPUnit's own failure exception extends RuntimeException,
        // so catching that type here would swallow the "it did not throw" report.
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Comment annotation pass failed');
            $reflection->getMethod('withDocumentNotes')->invoke($renderer, $subject);
        } finally {
            \ini_set('pcre.backtrack_limit', false === $backtrackLimit ? '1000000' : $backtrackLimit);
        }
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

    public function test_ordinary_yaml_reuse_still_renders(): void
    {
        // Rejecting `&`, `*` and `<<:` before parsing was tried and removed:
        // Yaml::parse() shares aliased nodes copy-on-write, so it prevented
        // nothing BoundedHtmlBuilder does not already bound, while refusing prose
        // like `- *starred phrase*`. This pins that legitimate aliases still
        // produce a table.
        $html = new MarkdownRenderer(new NullLogger())->render(
            "---\ndefaults: &defaults\n  team: platform\nowner:\n  <<: *defaults\ntags: [a, b]\n---\n\nBody.\n",
        );

        self::assertStringContainsString('lp-front-matter', $html);
        self::assertStringContainsString('platform', $html);
    }

    public function test_an_oversized_front_matter_block_is_refused(): void
    {
        $block = '';
        for ($key = 0; $key < 2_000; ++$key) {
            $block .= sprintf("key%d: %s\n", $key, str_repeat('x', 40));
        }
        self::assertGreaterThan(16_384, \strlen($block));

        $html = new MarkdownRenderer(new NullLogger())->render("---\n{$block}---\n\nBody.\n");

        self::assertStringNotContainsString('lp-front-matter', $html);
        self::assertStringContainsString('Body.', $html);
    }

    public function test_a_broad_alias_tree_is_bounded(): void
    {
        // Breadth rather than depth. Latching stops the traversal only because
        // every budget check sits above its loop and BoundedHtmlBuilder::each()
        // stops the loops themselves; a check moved below a loop would let each
        // ancestor keep walking its remaining siblings, and with a fan-out this
        // wide that is exponential in calls even though each call is cheap.
        $fanout = 30;
        $leaves = implode(', ', array_fill(0, $fanout, '"x"'));
        $yaml = "---\na0: &a0 [{$leaves}]\n";
        for ($level = 1; $level <= 6; ++$level) {
            $references = implode(', ', array_fill(0, $fanout, sprintf('*a%d', $level - 1)));
            $yaml .= sprintf("a%d: &a%d [%s]\n", $level, $level, $references);
        }
        // Roughly 2e10 logical leaves from about a kilobyte of source.
        self::assertLessThan(2_000, \strlen($yaml));

        $started = microtime(true);
        $html = new MarkdownRenderer(new NullLogger())->render($yaml."---\n\nBody.\n");

        self::assertStringNotContainsString('lp-front-matter', $html);
        self::assertLessThan(2.0, microtime(true) - $started);
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

    public function test_front_matter_that_cannot_be_tabulated_is_shown_verbatim(): void
    {
        // Parsed as Markdown instead, the closing `---` turns the lines above it
        // into a setext heading — and HeadingExtractor reads the rendered HTML, so
        // the contents panel listed a section the document never wrote.
        $html = new MarkdownRenderer(new NullLogger())->render("---\njust a string\n---\n\n## Real Section\n\nBody.\n");

        self::assertStringContainsString("<pre><code>---\njust a string\n---</code></pre>", $html);
        self::assertStringNotContainsString('heading-just-a-string', $html);
        self::assertStringContainsString('<h2 id="heading-real-section">Real Section</h2>', $html);
        self::assertStringContainsString('<p>Body.</p>', $html);
    }

    public function test_unparseable_front_matter_is_escaped_rather_than_rendered(): void
    {
        $html = new MarkdownRenderer(new NullLogger())->render("---\ntitle: \"<b>x\n  bad: [1, 2\n---\n\nBody.\n");

        self::assertStringContainsString('&lt;b&gt;x', $html);
        self::assertStringNotContainsString('<b>', $html);
    }

    /**
     * With everything unlisted blocked by default, the allow-list is the only
     * thing keeping any of these tags — each would silently flatten to bare text
     * if it ever fell off it, and no test elsewhere would notice.
     */
    #[DataProvider('markupTheAllowListMustKeep')]
    public function test_allowed_markup_still_renders(string $markdown, string $expected): void
    {
        $html = new MarkdownRenderer(new NullLogger())->render($markdown);

        self::assertStringContainsString($expected, $html);
    }

    /** @return iterable<string, array{string, string}> */
    public static function markupTheAllowListMustKeep(): iterable
    {
        yield 'emphasis' => ['*a*', '<em>a</em>'];
        yield 'strong' => ['**a**', '<strong>a</strong>'];
        yield 'blockquote' => ['> quoted', '<blockquote>'];
        yield 'ordered list start' => ["3. c\n4. d", '<ol start="3">'];
        yield 'unordered list' => ['- a', '<li>a</li>'];
        yield 'thematic break' => ["a\n\n***\n\nb", '<hr />'];
        yield 'hard line break' => ["a  \nb", '<br />'];
        yield 'fenced code language' => ["```php\n\$a = 1;\n```", '<code class="language-php">'];
        yield 'inline code' => ['`x`', '<code>x</code>'];
        yield 'table alignment' => ["| a | b |\n|:--|--:|\n| 1 | 2 |", '<th align="left">a</th>'];
        yield 'table body cell' => ["| a |\n|--|\n| 1 |", '<td>1</td>'];
        yield 'hand-written header cell' => ['<table><tr><th scope="row" colspan="2">h</th></tr></table>', '<th scope="row" colspan="2">h</th>'];
        yield 'relative link' => ['[x](/p/1)', '<a href="/p/1">x</a>'];
        yield 'image' => ['![alt](https://example.com/i.png)', '<img src="https://example.com/i.png" alt="alt"'];
        yield 'strikethrough' => ['<s>gone</s>', '<s>gone</s>'];
        yield 'definition list' => ['<dl><dt>t</dt><dd>d</dd></dl>', '<dl><dt>t</dt><dd>d</dd></dl>'];
        yield 'abbreviation' => ['<abbr title="t">A</abbr>', '<abbr title="t">A</abbr>'];
        yield 'insertion' => ['<ins datetime="2026-01-01">new</ins>', '<ins datetime="2026-01-01">new</ins>'];
        yield 'deletion' => ['<del datetime="2026-01-01">old</del>', '<del datetime="2026-01-01">old</del>'];
        yield 'superscript' => ['x<sup>1</sup>', '<sup>1</sup>'];
        yield 'subscript' => ['x<sub>1</sub>', '<sub>1</sub>'];
        yield 'keyboard' => ['<kbd>K</kbd>', '<kbd>K</kbd>'];
        yield 'sample' => ['<samp>out</samp>', '<samp>out</samp>'];
        yield 'variable' => ['<var>n</var>', '<var>n</var>'];
        yield 'mark' => ['<mark>hit</mark>', '<mark>hit</mark>'];
        yield 'small' => ['<small>fine</small>', '<small>fine</small>'];
        yield 'quotation' => ['<q cite="/c">said</q>', '<q cite="/c">said</q>'];
        yield 'citation' => ['<cite>src</cite>', '<cite>src</cite>'];
        yield 'span' => ['<span>s</span>', '<span>s</span>'];
        yield 'div' => ['<div>d</div>', '<div>d</div>'];
    }

    public function test_an_element_the_allow_list_never_heard_of_keeps_its_text(): void
    {
        // The point of blocking rather than dropping: plainText() is the basis every
        // comment anchor is measured against, so an unanticipated tag must not take
        // a paragraph with it.
        $html = new MarkdownRenderer(new NullLogger())->render('<p>before <foobar>kept</foobar> after</p>');

        self::assertStringNotContainsString('foobar', $html);
        self::assertStringContainsString('before kept after', $html);
    }

    #[DataProvider('markupWhoseTextMustStayOut')]
    public function test_code_never_becomes_prose(string $markdown, string $absent): void
    {
        // The exceptions to blocking: text that is code. `style` cannot be named in
        // the sanitizer config at all in a body context, which is why the tree is
        // stripped of it before the sanitizer sees it.
        $html = new MarkdownRenderer(new NullLogger())->render($markdown."\n\nBody.\n");

        self::assertStringNotContainsString($absent, $html);
        self::assertStringContainsString('<p>Body.</p>', $html);
    }

    /** @return iterable<string, array{string, string}> */
    public static function markupWhoseTextMustStayOut(): iterable
    {
        yield 'script' => ['<script>alert(1)</script>', 'alert'];
        yield 'stylesheet' => ['<style>p { color: red }</style>', 'color'];
        yield 'document title' => ['<title>Spoofed</title>', 'Spoofed'];
    }
}
