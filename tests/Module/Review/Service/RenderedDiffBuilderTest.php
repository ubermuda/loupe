<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Review\Service\MarkdownDiffer;
use App\Module\Review\Service\MarkdownRenderer;
use App\Module\Review\Service\RenderedDiffBuilder;
use App\Module\Review\ValueObject\DocumentDiff;
use App\Module\Review\ValueObject\RenderedDiff;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Cases run through the real renderer, since what a hunk has to describe is the
 * HTML a diff actually produces rather than a shape invented here.
 */
final class RenderedDiffBuilderTest extends TestCase
{
    private MarkdownDiffer $differ;
    private MarkdownRenderer $renderer;
    private RenderedDiffBuilder $builder;

    protected function setUp(): void
    {
        $this->differ = new MarkdownDiffer();
        $this->renderer = new MarkdownRenderer(new NullLogger(), new IdentityTranslator());

        /** @var TranslatorInterface&Stub $translator */
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $this->builder = new RenderedDiffBuilder($translator);
    }

    public function test_a_reworded_phrase_is_one_change(): void
    {
        $marked = $this->mark("The rollout takes one step.\n", "The rollout takes three steps.\n");

        self::assertSame(1, $marked->changeCount);
        self::assertSame(1, substr_count($marked->html, 'data-diff-navigation-target="hunk"'));
        self::assertStringContainsString('id="diff-hunk-1"', $marked->html);
    }

    /**
     * Blocks split on blank lines, so a removed section is a wrapper round the
     * heading and another round the paragraph. Counting those as two would send
     * the reviewer through one removal twice.
     */
    public function test_two_blocks_removed_together_are_one_change(): void
    {
        $marked = $this->mark(
            "Intro.\n\n## Scope\n\nThe scope paragraph.\n\nOutro.\n",
            "Intro.\n\nOutro.\n",
        );

        self::assertSame(2, substr_count($marked->html, 'lp-diff__mark--deleted'));
        self::assertSame(1, $marked->changeCount);
    }

    public function test_unchanged_text_between_marks_starts_a_new_change(): void
    {
        $marked = $this->mark(
            "Alpha here.\n\nUntouched.\n\nBeta here.\n",
            "Gamma here.\n\nUntouched.\n\nDelta here.\n",
        );

        self::assertSame(2, $marked->changeCount);
        self::assertStringContainsString('id="diff-hunk-2"', $marked->html);
    }

    /**
     * A thematic break carries no text, so a rule that reads text alone joins
     * the removals on either side of it into one change and hides the second
     * from `j`. A reader sees the rule between them as plainly as a word.
     */
    public function test_an_unchanged_rule_between_two_changes_separates_them(): void
    {
        $marked = $this->mark(
            "Kept intro.\n\nRemoved alpha.\n\n---\n\nRemoved beta.\n\nKept outro.\n",
            "Kept intro.\n\n---\n\nKept outro.\n",
        );

        self::assertStringContainsString('<hr>', $marked->html);
        self::assertSame(2, $marked->changeCount);
        self::assertSame(2, substr_count($marked->html, 'data-diff-navigation-target="hunk"'));
        self::assertStringContainsString('id="diff-hunk-2"', $marked->html);
    }

    /** An image puts its content in an attribute, so it too has no text of its own. */
    public function test_an_unchanged_image_between_two_changes_separates_them(): void
    {
        $marked = $this->mark(
            "Kept intro.\n\nRemoved alpha.\n\n![a diagram](https://example.com/d.png)\n\nRemoved beta.\n\nKept outro.\n",
            "Kept intro.\n\n![a diagram](https://example.com/d.png)\n\nKept outro.\n",
        );

        self::assertStringContainsString('<img src="https://example.com/d.png"', $marked->html);
        self::assertSame(2, $marked->changeCount);
        self::assertSame(2, substr_count($marked->html, 'data-diff-navigation-target="hunk"'));
    }

    /**
     * The other half of the rule, and the one it is easy to break while fixing
     * the first: an element that merely happens to be empty draws nothing the
     * document wrote, so it leaves the run open. Written as markup, because a
     * document cannot reliably produce an empty paragraph through the renderer.
     */
    public function test_an_empty_element_that_draws_nothing_keeps_a_run_open(): void
    {
        $mark = static fn (string $text): string => sprintf(
            '<del class="lp-diff__mark lp-diff__mark--deleted">%s</del>',
            $text,
        );

        $marked = $this->builder->build($mark('Alpha.')."\n<p></p>\n<span></span>\n".$mark('Beta.'));

        self::assertSame(1, $marked->changeCount);
    }

    public function test_every_mark_names_itself_and_only_the_first_of_a_run_is_a_target(): void
    {
        $marked = $this->mark("One word here.\n", "Two words here.\n");

        self::assertSame(1, substr_count($marked->html, 'data-diff-label="review.document.diff.mark.deleted"'));
        self::assertSame(1, substr_count($marked->html, 'data-diff-label="review.document.diff.mark.inserted"'));
        self::assertSame(1, substr_count($marked->html, 'data-diff-navigation-target'));
    }

    /**
     * The class is what the renderer mints for its own marks, so a document that
     * strikes its own text keeps that text out of the navigation.
     */
    public function test_a_document_s_own_del_is_neither_labelled_nor_a_target(): void
    {
        $marked = $this->builder->build('<p>A <del>struck</del> word.</p>');

        self::assertSame(0, $marked->changeCount);
        self::assertStringNotContainsString('data-diff-label', $marked->html);
        self::assertSame('<p>A <del>struck</del> word.</p>', $marked->html);
    }

    public function test_a_diff_with_no_marks_offers_nothing_to_jump_to(): void
    {
        self::assertSame(0, $this->builder->build('<p>Untouched.</p>')->changeCount);
    }

    private function mark(string $old, string $new): RenderedDiff
    {
        $diff = $this->differ->diff($old, $new);
        self::assertInstanceOf(DocumentDiff::class, $diff);

        return $this->builder->build($this->renderer->renderDiff($diff));
    }
}
