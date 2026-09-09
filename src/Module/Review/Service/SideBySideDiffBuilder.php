<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\ValueObject\SideBySideDiff;
use App\Module\Review\ValueObject\SideBySideRow;

/**
 * Pairs a {@see RenderedDiffBuilder} pane into the two versions it describes,
 * one row per top-level block.
 *
 * Each side is the block with the other side's marks dropped, which is the same
 * split {@see RenderedDiffBuilder::collectNewSideText()} makes to find the text
 * the newer version still holds. A whole block the revision removed is a mark of
 * its own, so it filters to nothing on the right and needs no case here.
 *
 * A side that filters to nothing is null rather than empty markup, so the page
 * can draw a slot the reader sees. The mark elements keep their ids, and each
 * one belongs to a single side, so the jump targets stay unique across the pane.
 */
final readonly class SideBySideDiffBuilder
{
    public function build(string $html): SideBySideDiff
    {
        $body = \Dom\HTMLDocument::createFromString($html, \LIBXML_NOERROR, 'UTF-8')->body
            ?? throw new \RuntimeException('Rendered diff parsed to a document with no body.');

        $rows = [];
        foreach ($body->childNodes as $node) {
            if (!$node instanceof \Dom\Element) {
                continue;
            }

            $old = $this->side($node, MarkdownRenderer::DIFF_MARK_CLASS.'--inserted');
            $new = $this->side($node, MarkdownRenderer::DIFF_MARK_CLASS.'--deleted');
            if (null === $old && null === $new) {
                continue;
            }

            $rows[] = new SideBySideRow($old, $new);
        }

        return new SideBySideDiff($rows);
    }

    /** @param string $dropped class of the marks the other side owns */
    private function side(\Dom\Element $block, string $dropped): ?string
    {
        if ($block->classList->contains($dropped)) {
            return null;
        }

        $clone = $block->cloneNode(true);
        if (!$clone instanceof \Dom\Element) {
            throw new \RuntimeException('Cloning a diff block did not produce an element.');
        }

        $marks = [];
        $this->collectMarks($clone, $dropped, $marks);
        foreach ($marks as $mark) {
            $mark->parentNode?->removeChild($mark);
        }

        return $this->isBlank($clone) ? null : $clone->outerHTML;
    }

    /**
     * Collects before it removes, because removing during the walk mutates the
     * live child list it reads.
     *
     * @param list<\Dom\Element> $marks
     */
    private function collectMarks(\Dom\Node $parent, string $dropped, array &$marks): void
    {
        foreach ($parent->childNodes as $node) {
            if (!$node instanceof \Dom\Element) {
                continue;
            }

            if ($node->classList->contains($dropped)) {
                $marks[] = $node;

                continue;
            }

            $this->collectMarks($node, $dropped, $marks);
        }
    }

    private function isBlank(\Dom\Element $element): bool
    {
        return '' === trim((string) $element->textContent) && !$this->drawsItsOwnContent($element);
    }

    /**
     * Whether the element puts something on the page without any text: a rule,
     * an image or a radio. Voidness is read from the serializer rather than from
     * a tag list that would go stale, since a void element takes no children.
     */
    private function drawsItsOwnContent(\Dom\Element $element): bool
    {
        if (0 === $element->childNodes->length) {
            return !str_ends_with($element->outerHTML, '</'.strtolower($element->tagName).'>');
        }

        foreach ($element->childNodes as $node) {
            if ($node instanceof \Dom\Element && $this->drawsItsOwnContent($node)) {
                return true;
            }
        }

        return false;
    }
}
