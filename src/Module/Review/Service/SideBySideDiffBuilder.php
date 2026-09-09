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
 * can draw a slot the reader sees.
 *
 * An unchanged block reaches both cells, so the older side renames every id the
 * renderer wrote, and every reference to it. A heading id would otherwise be in
 * the page twice, and a fragment would land in the wrong column. A jump target
 * keeps its id, since a mark is either deleted or inserted and reaches one cell
 * only.
 */
final readonly class SideBySideDiffBuilder
{
    /** What RenderedDiffBuilder marks the first mark of a run of changes with. */
    private const string NAVIGATION_ATTRIBUTE = 'data-diff-navigation-target';

    /**
     * The renderer namespaces a heading id with `heading-` and a decision id
     * with `lp-decision-`, so nothing it mints starts with this.
     */
    private const string OLD_SIDE_PREFIX = 'diff-old-';

    /** Attributes that name an id, so a renamed control keeps its label. */
    private const array ID_REFERENCE_ATTRIBUTES = [
        'for', 'form', 'list', 'headers', 'aria-activedescendant', 'aria-controls',
        'aria-describedby', 'aria-details', 'aria-errormessage', 'aria-flowto',
        'aria-labelledby', 'aria-owns',
    ];

    public function build(string $html): SideBySideDiff
    {
        $body = \Dom\HTMLDocument::createFromString($html, \LIBXML_NOERROR, 'UTF-8')->body
            ?? throw new \RuntimeException('Rendered diff parsed to a document with no body.');

        /** @var list<array{0: ?\Dom\Element, 1: ?\Dom\Element}> $pairs */
        $pairs = [];
        /** @var array<string, string> $renamed */
        $renamed = [];

        foreach ($body->childNodes as $node) {
            if (!$node instanceof \Dom\Element) {
                continue;
            }

            $old = $this->side($node, MarkdownRenderer::DIFF_MARK_CLASS.'--inserted');
            $new = $this->side($node, MarkdownRenderer::DIFF_MARK_CLASS.'--deleted');
            if (null === $old && null === $new) {
                continue;
            }

            if (null !== $old) {
                $this->rename($old, $renamed);
            }

            $pairs[] = [$old, $new];
        }

        // A second pass, because a document's own `[jump](#heading-intro)` sits
        // in another block than the heading it names, and a label may precede
        // the control it names. Both need every rename before they are rewritten.
        $rows = [];
        foreach ($pairs as [$old, $new]) {
            if (null !== $old && [] !== $renamed) {
                $this->rewriteReferences($old, $renamed);
            }

            $rows[] = new SideBySideRow($old?->outerHTML, $new?->outerHTML);
        }

        return new SideBySideDiff($rows);
    }

    /** @param string $dropped class of the marks the other side owns */
    private function side(\Dom\Element $block, string $dropped): ?\Dom\Element
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

        return $this->isBlank($clone) ? null : $clone;
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

    /**
     * A jump target keeps its id, because no other cell can hold that mark.
     *
     * @param array<string, string> $renamed
     */
    private function rename(\Dom\Element $element, array &$renamed): void
    {
        $id = $element->getAttribute('id');
        if (null !== $id && '' !== $id && !$element->hasAttribute(self::NAVIGATION_ATTRIBUTE)) {
            $renamed[$id] = self::OLD_SIDE_PREFIX.$id;
            $element->setAttribute('id', $renamed[$id]);
        }

        foreach ($element->childNodes as $node) {
            if ($node instanceof \Dom\Element) {
                $this->rename($node, $renamed);
            }
        }
    }

    /** @param array<string, string> $renamed */
    private function rewriteReferences(\Dom\Element $element, array $renamed): void
    {
        foreach (self::ID_REFERENCE_ATTRIBUTES as $attribute) {
            $value = $element->getAttribute($attribute);
            if (null === $value || '' === trim($value)) {
                continue;
            }

            $element->setAttribute($attribute, implode(' ', array_map(
                static fn (string $token): string => $renamed[$token] ?? $token,
                preg_split('/\s+/', trim($value)) ?: [],
            )));
        }

        $href = $element->getAttribute('href');
        if (null !== $href && str_starts_with($href, '#') && isset($renamed[substr($href, 1)])) {
            $element->setAttribute('href', '#'.$renamed[substr($href, 1)]);
        }

        foreach ($element->childNodes as $node) {
            if ($node instanceof \Dom\Element) {
                $this->rewriteReferences($node, $renamed);
            }
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
