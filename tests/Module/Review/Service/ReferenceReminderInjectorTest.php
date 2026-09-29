<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Review\Service\ReferenceReminderInjector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReferenceReminderInjectorTest extends TestCase
{
    private ReferenceReminderInjector $injector;

    protected function setUp(): void
    {
        $this->injector = new ReferenceReminderInjector();
    }

    public function test_definitions_take_the_first_sentence_after_the_id(): void
    {
        $html = '<ul><li><strong>R3: Title.</strong> Detail that follows.</li></ul>';

        self::assertSame(['R3' => 'Title.'], $this->injector->definitions($html));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function separators(): iterable
    {
        yield 'colon' => ['<li><strong>H4:</strong> Cache the board.</li>'];
        yield 'em dash' => ['<li><strong>H4 —</strong> Cache the board.</li>'];
        yield 'hyphen' => ['<li><strong>H4 -</strong> Cache the board.</li>'];
        yield 'period' => ['<li><strong>H4.</strong> Cache the board.</li>'];
        yield 'separator after the strong' => ['<li><strong>H4</strong>: Cache the board.</li>'];
    }

    #[DataProvider('separators')]
    public function test_definitions_accept_each_separator(string $item): void
    {
        self::assertSame(['H4' => 'Cache the board.'], $this->injector->definitions('<ol>'.$item.'</ol>'));
    }

    public function test_definitions_ignore_an_item_with_no_separator_or_no_leading_strong(): void
    {
        $html = '<ul>'
            .'<li><strong>R1 is</strong> not a definition.</li>'
            .'<li>Text first, <strong>R2:</strong> then the id.</li>'
            .'<li><em>R4:</em> Not strong.</li>'
            .'<li><strong>R5.5</strong> is a version.</li>'
            .'<li><strong>r6:</strong> Lower case.</li>'
            .'</ul>';

        self::assertSame([], $this->injector->definitions($html));
    }

    public function test_definitions_read_a_loose_list_item(): void
    {
        $html = "<ul>\n<li>\n<p><strong>R1:</strong> Keep anchors stable. More text.</p>\n<p>Second paragraph.</p>\n</li>\n</ul>";

        self::assertSame(['R1' => 'Keep anchors stable.'], $this->injector->definitions($html));
    }

    public function test_definitions_skip_nested_lists_decode_entities_and_collapse_whitespace(): void
    {
        $html = "<ul><li><strong>R1:</strong> Tom &amp; Jerry\n   agree<ul><li>Nested.</li></ul></li></ul>";

        self::assertSame(['R1' => 'Tom & Jerry agree'], $this->injector->definitions($html));
    }

    public function test_definitions_end_the_sentence_only_before_whitespace(): void
    {
        $html = '<ul><li><strong>R1:</strong> Use v1.2 of the API! Then stop.</li></ul>';

        self::assertSame(['R1' => 'Use v1.2 of the API!'], $this->injector->definitions($html));
    }

    public function test_definitions_cap_a_long_sentence_with_an_ellipsis(): void
    {
        $html = '<ul><li><strong>R1:</strong> '.str_repeat('word ', 100).'</li></ul>';

        $text = $this->injector->definitions($html)['R1'];

        self::assertSame(200, mb_strlen($text));
        self::assertStringEndsWith('…', $text);
    }

    public function test_the_first_definition_of_an_id_wins(): void
    {
        $html = '<ul><li><strong>R1:</strong> First.</li><li><strong>R1:</strong> Second.</li></ul>';

        self::assertSame(['R1' => 'First.'], $this->injector->definitions($html));
    }

    public function test_definitions_read_a_nested_definition(): void
    {
        $html = '<ul><li>Group<ul><li><strong>R7:</strong> Inner.</li></ul></li></ul>';

        self::assertSame(['R7' => 'Inner.'], $this->injector->definitions($html));
    }

    public function test_inject_returns_the_input_when_nothing_is_defined(): void
    {
        $html = '<p>R3 and <strong>R3:</strong></p>';

        self::assertSame($html, $this->injector->inject($html, []));
    }

    public function test_inject_wraps_a_mention_and_marks_the_first_defining_item(): void
    {
        $html = '<ul><li><strong>R3: Title.</strong> Detail.</li><li><strong>R3:</strong> Again.</li></ul><p>See R3.</p>';

        self::assertSame(
            '<ul><li id="ref-R3"><strong>R3: Title.</strong> Detail.</li><li><strong>R3:</strong> Again.</li></ul>'
            .'<p>See <span class="lp-ref" data-ref="R3" tabindex="0">R3</span>.</p>',
            $this->injector->inject($html, ['R3' => 'Title.']),
        );
    }

    public function test_inject_keeps_an_existing_id_on_the_defining_item(): void
    {
        $html = '<ul><li id="keep"><strong>R3:</strong> Detail.</li></ul>';

        self::assertSame($html, $this->injector->inject($html, ['R3' => 'Detail.']));
    }

    public function test_inject_leaves_an_undefined_id_plain(): void
    {
        $html = '<p>R3 and R4.</p>';

        self::assertSame(
            '<p><span class="lp-ref" data-ref="R3" tabindex="0">R3</span> and R4.</p>',
            $this->injector->inject($html, ['R3' => 'x']),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function glued(): iterable
    {
        yield 'hyphen after' => ['<p>R2-D2</p>'];
        yield 'hyphen before' => ['<p>X-R2</p>'];
        yield 'letter before' => ['<p>AR2</p>'];
        yield 'letter after' => ['<p>R2a</p>'];
        yield 'digit after' => ['<p>R2345</p>'];
        yield 'accented letter before' => ['<p>éR2</p>'];
    }

    #[DataProvider('glued')]
    public function test_inject_leaves_a_glued_mention_plain(string $html): void
    {
        self::assertSame($html, $this->injector->inject($html, ['R2' => 'x', 'D2' => 'x']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function skipped(): iterable
    {
        yield 'code' => ['<p><code>R1</code></p>'];
        yield 'pre' => ['<pre><code class="language-php">R1 <b>R1</b></code></pre>'];
        yield 'link' => ['<p><a href="#x">R1</a></p>'];
        yield 'label' => ['<label>R1</label>'];
        yield 'button' => ['<button type="button">R1</button>'];
        yield 'textarea' => ['<textarea>R1</textarea>'];
        yield 'script' => ['<script>R1</script>'];
        yield 'style' => ['<style>R1</style>'];
        yield 'attribute' => ['<p title="R1"></p>'];
        yield 'entity' => ['<p>&#X1;</p>'];
    }

    #[DataProvider('skipped')]
    public function test_inject_leaves_a_mention_in_a_skipped_place_plain(string $html): void
    {
        self::assertSame($html, $this->injector->inject($html, ['R1' => 'x', 'X1' => 'x']));
    }

    public function test_inject_resumes_after_a_skipped_element(): void
    {
        self::assertSame(
            '<p><code>R1</code> <span class="lp-ref" data-ref="R1" tabindex="0">R1</span></p>',
            $this->injector->inject('<p><code>R1</code> R1</p>', ['R1' => 'x']),
        );
    }

    public function test_inject_marks_a_loose_list_item(): void
    {
        $html = '<ul><li><p><strong>R1:</strong> Keep R1 short.</p></li></ul>';

        self::assertSame(
            '<ul><li id="ref-R1"><p><strong>R1:</strong> Keep <span class="lp-ref" data-ref="R1" tabindex="0">R1</span> short.</p></li></ul>',
            $this->injector->inject($html, $this->injector->definitions($html)),
        );
    }

    public function test_inject_adds_no_text(): void
    {
        $html = '<h2 id="goals">Goals for R1 and H2</h2>'
            .'<ol><li><strong>R1: Stable anchors.</strong> R1 needs H2.</li>'
            .'<li><p><strong>H2 —</strong> See R1, R1-x, AR1, &amp; <code>H2</code>.</p><ul><li>H2 again R1</li></ul></li></ol>'
            .'<p>Tom &amp; Jerry cite R1, H2 and R9. <a href="#r1">R1</a> <em>H2</em>!</p>'
            .'<table><tr><td>R1</td><td>H2</td></tr></table>';

        $out = $this->injector->inject($html, $this->injector->definitions($html));

        self::assertSame(strip_tags($html), strip_tags($out));
        self::assertSame(12, substr_count($out, 'class="lp-ref"'));
        self::assertStringContainsString('<li id="ref-R1">', $out);
        self::assertStringContainsString('<li id="ref-H2">', $out);
    }
}
