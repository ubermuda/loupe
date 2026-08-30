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
        $this->renderer = new MarkdownRenderer(new NullLogger());

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
