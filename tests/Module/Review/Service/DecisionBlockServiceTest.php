<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Review\Service\DecisionBlockService;
use App\Module\Review\Service\MarkdownRenderer;
use App\Module\Review\ValueObject\DecisionType;
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
use Psr\Log\NullLogger;

final class DecisionBlockServiceTest extends TestCase
{
    private const string FENCE = <<<'MD'
        Pick one.

        <!-- decision: deploy-target -->

        - ( ) Ship to staging first
        - ( ) Ship straight to **production**

        <!-- /decision -->

        After.
        MD;

    private const string MULTIPLE_FENCE = <<<'MD'
        <!-- decision: ship-with -->

        Which of these ship together?

        - [ ] The importer
        - [x] The exporter
        - [ ] The **admin** page

        <!-- /decision -->
        MD;

    private MarkdownRenderer $renderer;
    private DecisionBlockService $decisions;

    protected function setUp(): void
    {
        $this->renderer = new MarkdownRenderer(new NullLogger());
        $this->decisions = new DecisionBlockService();
    }

    public function test_a_fence_becomes_a_group_of_radios_keyed_by_its_identifier(): void
    {
        $html = $this->renderer->render(self::FENCE);

        // The id is the Turbo stream target a refused submission replaces.
        self::assertStringContainsString('<fieldset class="lp-decision" id="decision_block_deploy-target" data-decision-id="deploy-target" data-decision-type="single">', $html);
        self::assertStringContainsString('<input type="radio" name="lp-decision-deploy-target" value="0"', $html);
        self::assertStringContainsString('<input type="radio" name="lp-decision-deploy-target" value="1"', $html);
        // Inline formatting inside an option survives; the marker does not.
        self::assertStringContainsString('Ship straight to <strong>production</strong>', $html);
        self::assertStringNotContainsString('( )', $html);
    }

    /**
     * A block that takes several answers asks for them with `[ ]`, which is the
     * GFM task-list marker every other renderer already shows as a checkbox.
     */
    public function test_a_task_list_fence_becomes_a_group_of_checkboxes(): void
    {
        $html = $this->renderer->render(self::MULTIPLE_FENCE);

        self::assertStringContainsString('data-decision-id="ship-with" data-decision-type="multiple">', $html);
        self::assertStringContainsString('<input type="checkbox" name="lp-decision-ship-with" value="0"', $html);
        self::assertStringContainsString('<input type="checkbox" name="lp-decision-ship-with" value="2"', $html);
        self::assertStringNotContainsString('type="radio"', $html);

        $decisions = $this->decisions->extract($html);
        self::assertSame(DecisionType::Multiple, $decisions[0]->type);
        // Both marker spellings are stripped, and the ticked one is no answer:
        // the reviewer's clicks are what the app stores, not the author's.
        self::assertSame(['The importer', 'The exporter', 'The admin page'], $decisions[0]->options);
    }

    /**
     * Neither marker leaves anything behind in the text, because the anchor
     * basis is strip_tags() of the stored HTML and two stray characters shift
     * every comment below the block.
     *
     * @param non-empty-string $markdown
     */
    #[DataProvider('markedFences')]
    public function test_a_marker_never_reaches_the_anchor_basis(string $markdown, DecisionType $type): void
    {
        $html = $this->renderer->render($markdown);
        $text = strip_tags($html);

        self::assertSame($type, $this->decisions->extract($html)[0]->type);
        self::assertStringNotContainsString('[ ]', $text);
        self::assertStringNotContainsString('[x]', $text);
        self::assertStringNotContainsString('( )', $text);
    }

    /**
     * @return iterable<string, array{string, DecisionType}>
     */
    public static function markedFences(): iterable
    {
        yield 'single markers' => [self::FENCE, DecisionType::Single];
        yield 'multiple markers' => [self::MULTIPLE_FENCE, DecisionType::Multiple];
        yield 'markers on an ordered list' => [
            "<!-- decision: ordered -->\n\n1. [ ] A\n2. [x] B\n\n<!-- /decision -->\n",
            DecisionType::Multiple,
        ];
    }

    /**
     * An unmarked list is what every fence written before multi-choice looks
     * like, so it stays a single-choice block and its text is left alone.
     */
    public function test_an_unmarked_list_stays_a_single_choice_block(): void
    {
        $html = $this->renderer->render(
            "<!-- decision: plain -->\n\n- Ship it (now)\n- Wait\n\n<!-- /decision -->\n",
        );

        $decisions = $this->decisions->extract($html);
        self::assertSame(DecisionType::Single, $decisions[0]->type);
        self::assertSame(['Ship it (now)', 'Wait'], $decisions[0]->options);
        self::assertStringContainsString('type="radio"', $html);
    }

    /**
     * Nothing can tell which kind of block a disagreeing list asks for, and
     * guessing one records answers against a block the author never asked for.
     *
     * @param non-empty-string $markdown
     */
    #[DataProvider('mixedMarkerLists')]
    public function test_a_list_whose_markers_disagree_degrades_to_a_plain_list(string $markdown): void
    {
        $html = $this->renderer->render($markdown);

        self::assertStringNotContainsString('lp-decision', $html);
        self::assertStringNotContainsString('LPDECISION', $html);
        self::assertSame([], $this->decisions->extract($html));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mixedMarkerLists(): iterable
    {
        yield 'both markers' => ["<!-- decision: mixed -->\n\n- ( ) A\n- [ ] B\n\n<!-- /decision -->\n"];
        yield 'one item unmarked' => ["<!-- decision: mixed -->\n\n- [ ] A\n- B\n\n<!-- /decision -->\n"];
        yield 'one item marked' => ["<!-- decision: mixed -->\n\n- A\n- ( ) B\n\n<!-- /decision -->\n"];
    }

    /**
     * A card states the question it is asking, so the options are not read as a
     * bare list. The paragraph is the author's own text and the only text the
     * block contributes — the eyebrow above it is generated by CSS, because the
     * card sits in the pane comment anchors are measured against.
     */
    public function test_a_paragraph_before_the_options_becomes_the_cards_question(): void
    {
        $html = $this->renderer->render(
            "<!-- decision: deploy-target -->\n\nWhere should the first release go?\n\n1. Ship to staging first\n2. Ship straight to production\n\n<!-- /decision -->\n",
        );

        self::assertStringContainsString(
            '<legend class="lp-decision__prompt">Where should the first release go?</legend>',
            $html,
        );
        self::assertStringContainsString('<div class="lp-decision__options">', $html);
        self::assertCount(2, $this->decisions->extract($html)[0]->options);
    }

    /** A fence that asks nothing still converts; the card simply carries no question. */
    public function test_options_without_a_question_still_convert(): void
    {
        $html = $this->renderer->render(self::FENCE);

        self::assertStringContainsString('<legend class="lp-decision__prompt"></legend>', $html);
        self::assertCount(2, $this->decisions->extract($html)[0]->options);
    }

    /**
     * Two paragraphs are past what a card can show, so the block degrades to the
     * prose it already was — and must keep both, since an author who wrote a
     * sentence should never find it deleted from the rendered document.
     */
    public function test_a_block_with_more_prose_than_a_question_keeps_all_of_it(): void
    {
        $html = $this->renderer->render(
            "<!-- decision: deploy-target -->\n\nFirst paragraph.\n\nSecond paragraph.\n\n1. Ship to staging first\n\n<!-- /decision -->\n",
        );

        self::assertStringNotContainsString('<fieldset', $html);
        self::assertStringContainsString('First paragraph.', $html);
        self::assertStringContainsString('Second paragraph.', $html);
        self::assertStringContainsString('Ship to staging first', $html);
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

    /**
     * Block ids and option ids share one namespace, and a document chooses part
     * of both. Joined with `-` they could meet: `x-0` and `block-x` together
     * minted `decision-block-x-0` as a block and again as an option, so a Turbo
     * stream aimed at the block could replace a radio instead. `_` cannot appear
     * inside a document's id, which is what keeps the two apart.
     */
    public function test_no_pair_of_decision_ids_can_mint_the_same_element_id(): void
    {
        $html = $this->renderer->render(
            "<!-- decision: x-0 -->\n\n- [ ] A\n\n<!-- /decision -->\n\n<!-- decision: block-x -->\n\n- [ ] B\n\n<!-- /decision -->\n",
        );

        preg_match_all('~ id="([^"]+)"~', $html, $ids);

        self::assertNotEmpty($ids[1], 'the fences must actually have been converted');
        self::assertSame(array_unique($ids[1]), $ids[1], 'a document minted the same DOM id twice');
    }

    /**
     * Every other malformed shape degrades where it stands. This one used to
     * reach downstream: the unclosed opener left a sentinel, the pairing regex
     * matched it against the LATER fence's closer, and both lists came back as
     * ordinary markup — so a typo in one block silently cost a correct block
     * its controls, with no error anywhere.
     *
     * @param non-empty-string $markdown
     * @param list<string>     $expected
     */
    #[DataProvider('fencesAroundAValidOne')]
    public function test_a_malformed_fence_never_costs_a_valid_one_its_controls(string $markdown, array $expected): void
    {
        $decisions = $this->decisions->extract($this->renderer->render($markdown));

        self::assertSame($expected, array_map(static fn (object $d): string => $d->id, $decisions));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function fencesAroundAValidOne(): iterable
    {
        $valid = "<!-- decision: good -->\n\n- [ ] X\n- [ ] Y\n\n<!-- /decision -->\n";

        yield 'unmatched opener before it' => ["<!-- decision: bad -->\n\n- [ ] A\n\n".$valid, ['good']];
        yield 'unmatched opener after it' => [$valid."\n<!-- decision: bad -->\n\n- [ ] A\n", ['good']];
        yield 'unmatched closer before it' => ["<!-- /decision -->\n\n".$valid, ['good']];
        yield 'two unmatched openers before it' => ["<!-- decision: b1 -->\n\n- [ ] A\n\n<!-- decision: b2 -->\n\n- [ ] B\n\n".$valid, ['good']];
        // The closer pairs with the nearest opener, so the inner fence is the
        // one that converts and the abandoned outer list renders as prose.
        yield 'opened inside another opener' => ["<!-- decision: outer -->\n\n- [ ] A\n\n".$valid, ['good']];
        yield 'two valid fences still both convert' => [$valid."\n<!-- decision: other -->\n\n- [ ] Z\n\n<!-- /decision -->\n", ['good', 'other']];
    }

    /**
     * A block holding more than a question and a list keeps its markup, like
     * every other shape a card cannot show. What it must not do is reach past
     * its own closer for a later block's list: the next fence is well-formed
     * and would lose its controls, with both blocks read as one.
     */
    public function test_a_block_with_prose_after_its_list_leaves_the_next_block_alone(): void
    {
        $html = $this->renderer->render(
            "<!-- decision: alpha -->\n\nQuestion A?\n\n1. x\n\nTrailing note.\n\n<!-- /decision -->\n\n"
            ."<!-- decision: beta -->\n\nQuestion B?\n\n1. y\n\n<!-- /decision -->\n",
        );

        $decisions = $this->decisions->extract($html);
        self::assertSame(['beta'], array_map(static fn (object $d): string => $d->id, $decisions));
        self::assertSame(['y'], $decisions[0]->options);
        // Alpha degrades where it stands, keeping every sentence its author wrote.
        self::assertStringContainsString('<p>Question A?</p>', $html);
        self::assertStringContainsString('<li>x</li>', $html);
        self::assertStringContainsString('<p>Trailing note.</p>', $html);
        self::assertStringNotContainsString('LPDECISION', $html);
    }

    /**
     * The same shape at scale, because the two are not the same problem.
     *
     * Keeping a match inside its own block by tempering the pattern works and
     * then stops: the temper costs a backtracking frame per character, so a few
     * tens of KB of prose makes the whole pass fail and NO block in the document
     * converts — including well-formed ones whose stored text then changes, and
     * with it every comment anchor measured against it.
     */
    public function test_a_large_block_still_leaves_the_next_block_alone(): void
    {
        $prose = wordwrap(str_repeat('lorem ipsum dolor sit amet ', 3_500), 80, "\n", true);

        $html = $this->renderer->render(
            "<!-- decision: alpha -->\n\nQuestion A?\n\n1. x\n\n".$prose."\n\n<!-- /decision -->\n\n"
            ."<!-- decision: beta -->\n\nQuestion B?\n\n1. y\n\n<!-- /decision -->\n",
        );

        self::assertGreaterThan(90_000, \strlen($html), 'the prose must be well past the ceiling a tempered pattern has');

        $decisions = $this->decisions->extract($html);
        self::assertSame(['beta'], array_map(static fn (object $d): string => $d->id, $decisions));
        self::assertSame(['y'], $decisions[0]->options);
        self::assertStringContainsString('<p>Question A?</p>', $html);
        self::assertStringNotContainsString('LPDECISION', $html);
    }

    /**
     * Three post-sanitize passes now run over the same string — annotations,
     * decision controls, heading ids — and a fence marker is itself an HTML
     * comment, which is what the annotation pass exists to make visible.
     *
     * A paired fence's markers are rewritten to sentinels during parsing, so the
     * comment renderer never sees a comment and hands them back untouched. An
     * unpaired marker keeps its literal and becomes an annotation like any other
     * comment, which is the outcome worth having: the author sees the marker
     * they mistyped instead of it vanishing.
     *
     * @param non-empty-string $markdown
     * @param list<string>     $expectedIds
     */
    #[DataProvider('fencesBesideAnnotations')]
    public function test_fences_and_annotations_do_not_rewrite_each_other(
        string $markdown,
        array $expectedIds,
        bool $expectAnnotation,
    ): void {
        $html = $this->renderer->render($markdown);

        self::assertSame($expectedIds, array_map(static fn (object $d): string => $d->id, $this->decisions->extract($html)));
        self::assertSame($expectAnnotation, str_contains($html, 'lp-doc-note'));
        self::assertStringNotContainsString('LPDECISION', $html);
    }

    /**
     * @return iterable<string, array{string, list<string>, bool}>
     */
    public static function fencesBesideAnnotations(): iterable
    {
        $fence = "<!-- decision: good -->\n\n- [ ] X\n- [ ] Y\n\n<!-- /decision -->\n";

        yield 'a paired fence is not an annotation' => [$fence, ['good'], false];
        yield 'an unpaired opener becomes one' => ["<!-- decision: lonely -->\n\n- [ ] X\n", [], true];
        yield 'an annotation inside an option' => ["<!-- decision: good -->\n\n- [ ] X <!-- why -->\n- [ ] Y\n\n<!-- /decision -->\n", ['good'], true];
        yield 'a fence below front matter' => ["---\ntitle: T\n---\n\n".$fence, ['good'], false];
        // Both reach render()'s plain-converter fallback — a top-level sequence
        // is not a key/value map, and the second parses at all — so the fence
        // only survives because that converter carries the listener too.
        yield 'a fence below a front-matter sequence' => ["---\n- one\n- two\n---\n\n".$fence, ['good'], false];
        yield 'a fence below unparseable front matter' => ["---\n\tbad: [unclosed\n---\n\n".$fence, ['good'], false];
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

        $marked = $this->decisions->withSelections($html, ['deploy-target' => [1]], readOnly: false);
        self::assertStringContainsString('data-decision-option="deploy-target:1" checked>', $marked);
        self::assertStringContainsString('data-decision-option="deploy-target:0">', $marked);
        self::assertStringNotContainsString('disabled', $marked);

        $readOnly = $this->decisions->withSelections($html, ['deploy-target' => [1]], readOnly: true);
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
        $marked = $this->decisions->withSelections($html, ['deploy-target' => [1]], readOnly: true);

        self::assertSame(strip_tags($html), strip_tags($marked));
        self::assertNotSame($html, $marked);
    }

    /**
     * A tag-dense question, above the ceiling a non-possessive prompt group has.
     *
     * The prompt is the one part of a block still bounded in the pattern, so it
     * is the only part whose cost scales with what the author wrote. Left
     * non-possessive it spends a matching frame per `<` and the whole pass fails
     * a few tens of KB in — taking every well-formed block in the document with
     * it, this one's neighbour included.
     */
    public function test_a_tag_dense_question_still_converts(): void
    {
        $prompt = str_repeat('*a* ', 8_000);

        $html = $this->renderer->render(
            "<!-- decision: alpha -->\n\n".$prompt."\n\n- one\n- two\n\n<!-- /decision -->\n\n"
            ."<!-- decision: beta -->\n\nB?\n\n- y\n\n<!-- /decision -->\n",
        );

        // The tag count is what costs a frame each, not the Markdown length, and
        // a non-possessive group gives out at 8,190 of them.
        self::assertGreaterThan(8_190, substr_count($html, '<'), 'the question must be denser than a non-possessive group survives');

        $decisions = $this->decisions->extract($html);
        self::assertSame(['alpha', 'beta'], array_map(static fn (object $d): string => $d->id, $decisions));
        self::assertSame(['one', 'two'], $decisions[0]->options);
        self::assertStringNotContainsString('LPDECISION', $html);
    }

    /**
     * A block past the backtracking budget a spanning read would have spent.
     *
     * Read with a body that spans the closing tag, a list this long made
     * preg_match_all() fail and both readers reported no decisions at all — the
     * page showing five thousand options while the agent was told there were
     * none, and a refused submission finding nothing to put back.
     */
    public function test_a_block_larger_than_the_backtracking_budget_is_still_read_back(): void
    {
        $options = [];
        for ($i = 0; $i < 5_000; ++$i) {
            $options[] = '- option '.$i;
        }

        $html = $this->renderer->render(
            "<!-- decision: huge -->\n\n".implode("\n", $options)."\n\n<!-- /decision -->\n",
        );

        self::assertGreaterThan(1_000_000, \strlen($html), 'the block must be past pcre.backtrack_limit');

        $decisions = $this->decisions->extract($html);
        self::assertSame(['huge'], array_map(static fn (object $d): string => $d->id, $decisions));
        self::assertCount(5_000, $decisions[0]->options);
        self::assertSame('option 0', $decisions[0]->options[0]);

        // The same scan backs the markup a refused submission streams back.
        self::assertStringContainsString('data-decision-id="huge"', (string) $this->decisions->blockHtml($html, 'huge'));
    }

    /**
     * The anchor basis for a block that carries a question.
     *
     * Every comment's offsets are measured against `strip_tags()` of the stored
     * HTML, so what the conversion leaves in the text is what every anchor below
     * the block is counted from. The question is the author's own words and
     * belongs there; the eyebrow above it is drawn by CSS precisely so it does
     * not.
     */
    public function test_a_question_reaches_the_anchor_basis_and_the_machinery_does_not(): void
    {
        $html = $this->renderer->render(
            "Before.\n\n<!-- decision: deploy-target -->\n\nWhere should the first release go?\n\n"
            ."1. Ship to staging first\n2. Ship straight to production\n\n<!-- /decision -->\n\nAfter.\n",
        );

        $text = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        self::assertStringContainsString('Where should the first release go?', $text);
        self::assertStringContainsString('Ship to staging first', $text);
        self::assertSame(1, substr_count($text, 'Where should the first release go?'), 'the question is not duplicated by the legend');

        // None of the conversion's own vocabulary reaches the basis.
        self::assertStringNotContainsString('LPDECISION', $text);
        self::assertStringNotContainsString('lp-decision', $text);
        self::assertStringNotContainsString('Decision — pick one', $text);

        // And the prose either side still bounds it, so nothing was consumed.
        self::assertStringContainsString('Before.', $text);
        self::assertStringContainsString('After.', $text);
    }
}
