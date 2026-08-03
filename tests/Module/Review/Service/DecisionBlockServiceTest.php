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
     * The radios are minted after sanitization, so they are not the `<input>` a
     * document may write — that one is forced to type=checkbox, and a change
     * that routed these through it would turn every decision into a checkbox.
     */
    public function test_a_document_supplied_input_is_still_forced_to_a_checkbox(): void
    {
        self::assertStringContainsString(
            'type="checkbox"',
            $this->renderer->render('<input type="radio" checked>'),
        );
    }

    public function test_a_fence_quoted_inside_a_code_block_is_inert(): void
    {
        $html = $this->renderer->render("````\n<!-- decision: nope -->\n\n- [ ] A\n\n<!-- /decision -->\n````\n");

        self::assertStringNotContainsString('lp-decision', $html);
        self::assertSame([], $this->decisions->extract($html));
    }

    /**
     * @param non-empty-string $markdown
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedFences')]
    public function test_a_malformed_fence_degrades_to_the_list_it_already_was(string $markdown): void
    {
        $html = $this->renderer->render($markdown);

        self::assertStringNotContainsString('lp-decision', $html);
        // A sentinel that reached renderedHtml would sit in plainText() forever,
        // shifting every comment anchor below it on that version.
        self::assertStringNotContainsString('LPDECISION', $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedFences(): iterable
    {
        yield 'unmatched open' => ["<!-- decision: lonely -->\n\n- [ ] A\n"];
        yield 'unmatched close' => ["- [ ] A\n\n<!-- /decision -->\n"];
        yield 'no list inside' => ["<!-- decision: x -->\n\nJust prose.\n\n<!-- /decision -->\n"];
        yield 'nested list' => ["<!-- decision: nest -->\n\n- [ ] A\n  - inner\n- [ ] B\n\n<!-- /decision -->\n"];
        yield 'uppercase id' => ["<!-- decision: Bad_Id -->\n\n- [ ] A\n\n<!-- /decision -->\n"];
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
    #[\PHPUnit\Framework\Attributes\DataProvider('fenceFreeDocuments')]
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
        self::assertStringContainsString('value="1" id="decision-deploy-target-1" data-decision-option checked>', $marked);
        self::assertStringContainsString('value="0" id="decision-deploy-target-0" data-decision-option>', $marked);
        self::assertStringNotContainsString('disabled', $marked);

        $readOnly = $this->decisions->withSelections($html, ['deploy-target' => 1], readOnly: true);
        self::assertStringContainsString('data-decision-option checked disabled>', $readOnly);
        self::assertStringContainsString('data-decision-option disabled>', $readOnly);
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
