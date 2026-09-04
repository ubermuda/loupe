<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Service\MarkdownDiffer;
use App\Module\Review\Service\MarkdownRenderer;
use App\Module\Review\Service\RenderedDiffBuilder;
use App\Module\Review\ValueObject\DocumentDiff;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Guards the offsets a diff pane carries so that a comment made on it anchors
 * like any other.
 *
 * Every offset is a character offset into the newer version's
 * DocumentVersion::plainText(), which is the basis AnchorService measures a
 * quote against. Anything the newer version does not hold carries none.
 *
 * The renderer comes from the container rather than from `new`, so a change to
 * its constructor does not reach this file.
 */
final class RenderedDiffOffsetsTest extends KernelTestCase
{
    private MarkdownDiffer $differ;
    private MarkdownRenderer $renderer;
    private RenderedDiffBuilder $builder;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->differ = $container->get(MarkdownDiffer::class);
        $this->renderer = $container->get(MarkdownRenderer::class);
        $this->builder = $container->get(RenderedDiffBuilder::class);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function revisions(): iterable
    {
        yield 'a reworded phrase' => [
            "The rollout takes one step.\n",
            "The rollout takes three steps.\n",
        ];

        yield 'an added paragraph' => [
            "Intro.\n\nOutro.\n",
            "Intro.\n\nA brand new paragraph.\n\nOutro.\n",
        ];

        yield 'a removed section' => [
            "Intro.\n\n## Scope\n\nThe scope paragraph.\n\nOutro.\n",
            "Intro.\n\nOutro.\n",
        ];

        yield 'a changed list' => [
            "- one\n- two\n- three\n",
            "- one\n- two changed\n- three\n- four\n",
        ];

        yield 'a changed table cell' => [
            "| a | b |\n| --- | --- |\n| 1 | 2 |\n",
            "| a | b |\n| --- | --- |\n| 1 | 3 |\n",
        ];

        yield 'text after an emoji' => [
            "Ship it 🚀 today.\n",
            "Ship it 🚀 tomorrow.\n",
        ];
    }

    /**
     * The offset is what makes a marked run usable as an anchor, so every one of
     * them must name the place the newer version keeps that run.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('revisions')]
    public function test_every_marked_run_names_its_place_in_the_newer_version(string $old, string $new): void
    {
        $plainText = DocumentVersion::plainTextOf($this->renderer->render($new));
        $body = $this->pane($old, $new, $plainText);

        $marked = 0;
        foreach ($this->stampedRuns($body) as [$offset, $text]) {
            ++$marked;
            self::assertSame(
                $text,
                mb_substr($plainText, $offset, mb_strlen($text, 'UTF-8'), 'UTF-8'),
                sprintf('Run %s claims offset %d.', json_encode($text), $offset),
            );
        }

        self::assertGreaterThan(0, $marked);
    }

    /** Deleted text is in no version a comment can land on. */
    public function test_deleted_text_carries_no_offset(): void
    {
        $new = "Intro.\n\nOutro.\n";
        $body = $this->pane(
            "Intro.\n\n## Scope\n\nThe scope paragraph.\n\nOutro.\n",
            $new,
            DocumentVersion::plainTextOf($this->renderer->render($new)),
        );

        $deleted = $body->querySelectorAll('.lp-diff__mark--deleted');
        self::assertGreaterThan(0, $deleted->length);
        foreach ($deleted as $mark) {
            self::assertSame(
                0,
                $mark->querySelectorAll('['.RenderedDiffBuilder::OFFSET_ATTRIBUTE.']')->length,
            );
        }
    }

    /**
     * Wrapping an added block adds newlines the newer version never had. They
     * carry no offset, and the runs on either side of them still join up, which
     * is what lets a selection cross the block boundary.
     */
    public function test_the_runs_around_an_added_block_still_join_up(): void
    {
        $new = "Intro.\n\nA brand new paragraph.\n\nOutro.\n";
        $plainText = DocumentVersion::plainTextOf($this->renderer->render($new));
        $body = $this->pane("Intro.\n\nOutro.\n", $new, $plainText);

        $runs = $this->stampedRuns($body);
        $joined = '';
        $cursor = null;
        foreach ($runs as [$offset, $text]) {
            if (null !== $cursor && $offset !== $cursor) {
                self::fail(sprintf('Run %s starts at %d, not %d.', json_encode($text), $offset, $cursor));
            }
            $joined .= $text;
            $cursor = $offset + mb_strlen($text, 'UTF-8');
        }

        self::assertSame($plainText, $joined);
    }

    /** No plain text, no offsets: the pane is then read-only as it always was. */
    public function test_a_pane_built_without_the_newer_plain_text_carries_no_offsets(): void
    {
        $diff = $this->differ->diff("One step.\n", "Three steps.\n");
        self::assertInstanceOf(DocumentDiff::class, $diff);

        $html = $this->builder->build($this->renderer->renderDiff($diff))->html;

        self::assertStringNotContainsString(RenderedDiffBuilder::OFFSET_ATTRIBUTE, $html);
    }

    private function pane(string $old, string $new, string $plainText): \Dom\Element
    {
        $diff = $this->differ->diff($old, $new);
        self::assertInstanceOf(DocumentDiff::class, $diff);

        $html = $this->builder->build($this->renderer->renderDiff($diff), $plainText)->html;

        return \Dom\HTMLDocument::createFromString('<div id="pane">'.$html.'</div>', \LIBXML_NOERROR, 'UTF-8')
            ->getElementById('pane')
            ?? self::fail('The pane did not parse.');
    }

    /**
     * Each marked run as [offset, text], in document order.
     *
     * @return list<array{int, string}>
     */
    private function stampedRuns(\Dom\Element $pane): array
    {
        $runs = [];
        foreach ($pane->querySelectorAll('['.RenderedDiffBuilder::OFFSET_ATTRIBUTE.']') as $span) {
            $runs[] = [(int) $span->getAttribute(RenderedDiffBuilder::OFFSET_ATTRIBUTE), (string) $span->textContent];
        }

        return $runs;
    }
}
