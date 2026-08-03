<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Review\Service\DecisionBlockService;
use App\Module\Review\Service\MarkdownRenderer;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Parser\MarkdownParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecisionBlockServiceTest extends TestCase
{
    private const string FENCE = <<<'MD'
        Pick one.

        <!-- decision: deploy-target -->

        - [ ] Ship to staging first
        - [ ] Ship straight to **production**

        <!-- /decision -->

        After.
        MD;

    private MarkdownRenderer $renderer;
    private DecisionBlockService $decisions;

    protected function setUp(): void
    {
        $this->renderer = new MarkdownRenderer();
        $this->decisions = new DecisionBlockService();
    }

    public function test_a_fence_becomes_a_group_of_radios_keyed_by_its_identifier(): void
    {
        $html = $this->renderer->render(self::FENCE);

        self::assertStringContainsString('<fieldset class="lp-decision" data-decision-id="deploy-target">', $html);
        self::assertStringContainsString('<input type="radio" name="lp-decision-deploy-target" value="0"', $html);
        self::assertStringContainsString('<input type="radio" name="lp-decision-deploy-target" value="1"', $html);
        // Inline formatting inside an option survives; the task-list marker does not.
        self::assertStringContainsString('Ship straight to <strong>production</strong>', $html);
        self::assertStringNotContainsString('[ ]', $html);
    }

    /**
     * The radios exist only because toControls() mints them after the sanitizer
     * has run. Markup a document writes itself never becomes one.
     */
    public function test_a_document_cannot_write_its_own_control(): void
    {
        $html = $this->renderer->render('<input type="radio" name="lp-decision-x" value="0" data-decision-option="x:0">');

        self::assertStringNotContainsString('<input', $html);
        self::assertSame([], $this->decisions->extract($html));
    }

    /**
     * Reviewers refer to options by number, so an ordered list has to convert
     * too — the skill tells authors to number them.
     */
    public function test_an_ordered_list_converts_the_same_way_a_bullet_list_does(): void
    {
        $html = $this->renderer->render(
            "<!-- decision: deploy-target -->\n\n1. Ship to staging first\n2. Ship straight to production\n\n<!-- /decision -->\n",
        );

        $decisions = $this->decisions->extract($html);
        self::assertCount(1, $decisions);
        self::assertSame(['Ship to staging first', 'Ship straight to production'], $decisions[0]->options);
        self::assertStringContainsString('<input type="radio" name="lp-decision-deploy-target" value="1"', $html);
    }

    /**
     * An option's label reaches the agent and is what a stored answer is matched
     * against, so an illustrated option must not reduce to ''. Two image-only
     * options would otherwise store the same empty label and become
     * indistinguishable to everything downstream.
     *
     * @param non-empty-string $markdown
     * @param list<string>     $expected
     */
    #[DataProvider('illustratedOptions')]
    public function test_an_option_is_labelled_from_its_image_alt_text(string $markdown, array $expected): void
    {
        $html = $this->renderer->render("<!-- decision: art -->\n\n".$markdown."\n\n<!-- /decision -->\n");

        $decisions = $this->decisions->extract($html);
        self::assertCount(1, $decisions, 'the fence must actually have been converted');
        self::assertSame($expected, $decisions[0]->options);
        // The control still shows the picture; only the derived label changed.
        self::assertStringContainsString('<img', $html);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function illustratedOptions(): iterable
    {
        yield 'image only' => ["- ![Diagram](d.png)\n- Plain text", ['Diagram', 'Plain text']];
        // The shape a naive substitution runs together or doubles.
        yield 'text then image' => ['- Ship it ![icon](i.png)', ['Ship it icon']];
        yield 'image then text' => ['- ![icon](i.png) Ship it', ['icon Ship it']];
        yield 'image between words' => ['- Ship ![icon](i.png) it', ['Ship icon it']];
        // No alt is nothing to label with, exactly as for a heading.
        yield 'image with no alt' => ['- ![](d.png)', ['']];
        yield 'task marker then image' => ['- [ ] ![Diagram](d.png)', ['Diagram']];
    }

    public function test_a_fence_quoted_inside_a_code_block_is_inert(): void
    {
        $html = $this->renderer->render("````\n<!-- decision: nope -->\n\n- [ ] A\n\n<!-- /decision -->\n````\n");

        // The example is still rendered as the code block it was written as.
        self::assertStringContainsString('&lt;!-- decision: nope --&gt;', $html);
        self::assertStringContainsString('- [ ] A', $html);
        self::assertStringNotContainsString('lp-decision', $html);
        self::assertSame([], $this->decisions->extract($html));
    }

    /**
     * @param non-empty-string $markdown
     * @param non-empty-string $stillRendered
     */
    #[DataProvider('malformedFences')]
    public function test_a_malformed_fence_degrades_to_the_list_it_already_was(string $markdown, string $stillRendered): void
    {
        $html = $this->renderer->render($markdown);

        // The content survives — the block degrades, it does not disappear.
        self::assertStringContainsString($stillRendered, $html);
        self::assertStringNotContainsString('lp-decision', $html);
        // A sentinel that reached renderedHtml would sit in plainText() forever,
        // shifting every comment anchor below it on that version.
        self::assertStringNotContainsString('LPDECISION', $html);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedFences(): iterable
    {
        yield 'unmatched open' => ["<!-- decision: lonely -->\n\n- [ ] A\n", '<li>[ ] A</li>'];
        yield 'unmatched close' => ["- [ ] A\n\n<!-- /decision -->\n", '<li>[ ] A</li>'];
        yield 'no list inside' => ["<!-- decision: x -->\n\nJust prose.\n\n<!-- /decision -->\n", '<p>Just prose.</p>'];
        yield 'nested list' => ["<!-- decision: nest -->\n\n- [ ] A\n  - inner\n- [ ] B\n\n<!-- /decision -->\n", '<li>inner</li>'];
        yield 'uppercase id' => ["<!-- decision: Bad_Id -->\n\n- [ ] A\n\n<!-- /decision -->\n", '<li>[ ] A</li>'];
        yield 'id starting with a hyphen' => ["<!-- decision: -lead -->\n\n- [ ] A\n\n<!-- /decision -->\n", '<li>[ ] A</li>'];
        yield 'id one character over the ceiling' => ['<!-- decision: '.str_repeat('a', 65)." -->\n\n- [ ] A\n\n<!-- /decision -->\n", '<li>[ ] A</li>'];
    }

    public function test_an_id_at_the_exact_ceiling_still_converts(): void
    {
        $id = str_repeat('a', 64);
        $html = $this->renderer->render("<!-- decision: $id -->\n\n- [ ] A\n\n<!-- /decision -->\n");

        self::assertSame([$id], array_map(static fn (object $d): string => $d->id, $this->decisions->extract($html)));
    }

    /**
     * Two blocks sharing an id would answer each other's question and collide on
     * the minted element ids, so the second is left as an ordinary list.
     */
    public function test_a_repeated_identifier_converts_only_its_first_block(): void
    {
        $html = $this->renderer->render(
            "<!-- decision: dup -->\n\n- [ ] A\n\n<!-- /decision -->\n\n<!-- decision: dup -->\n\n- [ ] B\n\n<!-- /decision -->\n",
        );

        $decisions = $this->decisions->extract($html);
        self::assertCount(1, $decisions);
        self::assertSame(['A'], $decisions[0]->options);
        self::assertStringNotContainsString('LPDECISION', $html);
    }

    /**
     * The sentinel carries a per-instance random component precisely so a
     * document cannot write one and mint controls out of a plain list.
     */
    public function test_a_document_cannot_forge_the_sentinel(): void
    {
        $html = $this->renderer->render("LPDECISION_0000_OPEN_x_END\n\n- [ ] A\n\nLPDECISION_0000_CLOSE\n");

        // The forged text is the author's own, so it is preserved verbatim
        // alongside the list — neither converted nor swallowed.
        self::assertStringContainsString('LPDECISION_0000_OPEN_x_END', $html);
        self::assertStringContainsString('<li>[ ] A</li>', $html);
        self::assertStringNotContainsString('lp-decision', $html);
        self::assertSame([], $this->decisions->extract($html));
    }

    public function test_extract_reports_the_identifier_and_the_option_labels_as_plain_text(): void
    {
        $decisions = $this->decisions->extract($this->renderer->render(self::FENCE));

        self::assertCount(1, $decisions);
        self::assertSame('deploy-target', $decisions[0]->id);
        self::assertSame(['Ship to staging first', 'Ship straight to production'], $decisions[0]->options);
        self::assertSame('Ship straight to production', $decisions[0]->optionAt(1));
        self::assertNull($decisions[0]->optionAt(2));
    }

    /**
     * The two halves of "a document without a fence renders exactly as it did
     * before decisions existed", which is what keeps every stored anchor valid.
     *
     * Nothing else in the pipeline changed: the listener is the only addition
     * before sanitization, and toControls() the only one after. So proving the
     * listener rewrites no node and toControls() returns its input is proving
     * the whole rendering is byte-identical.
     */
    #[DataProvider('fenceFreeDocuments')]
    public function test_a_document_without_a_fence_passes_through_both_passes_untouched(string $markdown): void
    {
        $environment = new Environment(['html_input' => 'allow', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new TableExtension());

        $parsed = new MarkdownParser($environment)->parse($markdown);
        $before = self::htmlBlockLiterals($parsed);
        $this->decisions->markParsedDocument(new DocumentParsedEvent($parsed));

        self::assertSame($before, self::htmlBlockLiterals($parsed));

        $html = new MarkdownConverter($environment)->convert($markdown)->getContent();
        self::assertSame($html, $this->decisions->toControls($html));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fenceFreeDocuments(): iterable
    {
        yield 'prose and headings' => ["# Title\n\nSome **prose** with a [link](https://example.com).\n\n## Second\n\nMore.\n"];
        yield 'a plain task list' => ["- [ ] one\n- [x] two\n- three\n"];
        yield 'a table' => ["| a | b |\n|:--|--:|\n| 1 | 2 |\n"];
        yield 'a code block' => ["```php\n\$x = 1;\n```\n"];
        yield 'an unrelated html comment' => ["<!-- just a note -->\n\n- [ ] one\n"];
        yield 'a comment that only looks like a fence' => ["<!-- decisions: x -->\n\n- [ ] one\n\n<!-- /decisions -->\n"];
        yield 'raw html' => ["<div><p>kept</p><script>alert(1)</script></div>\n"];
        yield 'the word decision in prose' => ["The decision: ship it. Also `<!-- decision: x -->` inline.\n"];
        yield 'an ordered list' => ["1. first\n2. second\n"];
        yield 'an ordered list not starting at one' => ["3. third\n4. fourth\n"];
        yield 'a nested ordered list' => ["1. outer\n   1. inner\n2. outer again\n"];
        yield 'a blockquoted task list' => ["> quote\n>\n> - [ ] nested task\n"];
        yield 'an opening marker with no id' => ["<!-- decision -->\n\n- [ ] one\n"];
        yield 'an uppercase marker' => ["<!-- DECISION: x -->\n\n- [ ] one\n\n<!-- /DECISION -->\n"];
        yield 'repeated headings' => ["# Dup\n\n# Dup\n\n# Dup\n"];
        yield 'entities and non-ascii' => ["Text with &amp; &lt;tags&gt; and émojis ünïcode.\n"];
        yield 'empty' => [''];
    }

    /**
     * HtmlSanitizer cuts its input with a raw substr() at getMaxInputLength()
     * before parsing, so a large enough document loses the tail of a sentinel
     * and the remaining fragment is stored inside renderedHtml — where it sits
     * in plainText() forever, shifting every anchor below it.
     *
     * Driven at the sweep rather than through render(): reproducing the real cut
     * needs a 1 MB document per offset, and sweeping every cut position of the
     * sentinel here is both faster and stricter than sampling document sizes.
     */
    public function test_a_sentinel_severed_by_the_sanitizers_truncation_never_survives(): void
    {
        $sentinels = self::sentinelsFor($this->decisions, "<!-- decision: t -->\n\n- [ ] A\n\n<!-- /decision -->\n");
        self::assertCount(2, $sentinels, 'the fence must actually have produced both sentinels');

        foreach ($sentinels as $sentinel) {
            for ($cut = 1; $cut < strlen($sentinel); ++$cut) {
                $truncated = '<p>kept</p>'.substr($sentinel, 0, $cut);

                $swept = $this->decisions->toControls($truncated);
                self::assertSame('<p>kept</p>', $swept, sprintf('fragment of length %d leaked', $cut));
            }
        }
    }

    /**
     * The sentinels the listener writes for a given source, read straight off the
     * AST — the only way to see a nonce the service keeps to itself.
     *
     * @return list<string>
     */
    private static function sentinelsFor(DecisionBlockService $decisions, string $markdown): array
    {
        $environment = new Environment(['html_input' => 'allow', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension());

        $parsed = new MarkdownParser($environment)->parse($markdown);
        $decisions->markParsedDocument(new DocumentParsedEvent($parsed));

        return array_values(array_filter(
            self::htmlBlockLiterals($parsed),
            static fn (string $literal): bool => str_starts_with($literal, 'LPDECISION_'),
        ));
    }

    /**
     * @return list<string>
     */
    private static function htmlBlockLiterals(Document $document): array
    {
        $literals = [];
        $walker = $document->walker();
        while (null !== $event = $walker->next()) {
            $node = $event->getNode();
            if ($event->isEntering() && $node instanceof HtmlBlock) {
                $literals[] = $node->getLiteral();
            }
        }

        return $literals;
    }

    public function test_a_recorded_answer_is_shown_and_locked_on_an_earlier_version(): void
    {
        $html = $this->renderer->render(self::FENCE);

        $marked = $this->decisions->withSelections($html, ['deploy-target' => 1], readOnly: false);
        self::assertStringContainsString('data-decision-option="deploy-target:1" checked>', $marked);
        self::assertStringContainsString('data-decision-option="deploy-target:0">', $marked);
        self::assertStringNotContainsString('disabled', $marked);

        $readOnly = $this->decisions->withSelections($html, ['deploy-target' => 1], readOnly: true);
        self::assertStringContainsString('data-decision-option="deploy-target:1" checked disabled>', $readOnly);
        self::assertStringContainsString('data-decision-option="deploy-target:0" disabled>', $readOnly);
    }

    /**
     * Applied at display time, so it must add attributes and nothing else — the
     * anchor basis is strip_tags() of the stored HTML, which attributes never
     * survive but text does.
     */
    public function test_showing_an_answer_leaves_the_anchor_basis_untouched(): void
    {
        $html = $this->renderer->render(self::FENCE);
        $marked = $this->decisions->withSelections($html, ['deploy-target' => 1], readOnly: true);

        self::assertSame(strip_tags($html), strip_tags($marked));
        self::assertNotSame($html, $marked);
    }
}
