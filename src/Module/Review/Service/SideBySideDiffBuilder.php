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
 * renderer wrote, and the references to it inside that block. A heading id would
 * otherwise be in the page twice, and a fragment would land in the wrong column.
 * A jump target keeps its id, since a mark is either deleted or inserted and
 * reaches one cell only.
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

        $rows = [];
        foreach ($body->childNodes as $node) {
            if (!$node instanceof \Dom\Element) {
                continue;
            }

            $old = $this->side($node, MarkdownRenderer::DIFF_MARK_CLASS.'--inserted', true);
            $new = $this->side($node, MarkdownRenderer::DIFF_MARK_CLASS.'--deleted', false);
            if (null === $old && null === $new) {
                continue;
            }

            $rows[] = new SideBySideRow($old, $new);
        }

        return new SideBySideDiff($rows);
    }

    /**
     * @param string $dropped class of the marks the other side owns
     * @param bool   $isOld   whether this side yields a shared id to the other
     */
    private function side(\Dom\Element $block, string $dropped, bool $isOld): ?string
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

        if ($isOld) {
            $this->renameIds($clone);
        }

        return $this->isBlank($clone) ? null : $clone->outerHTML;
    }

    /**
     * Renames first and rewrites after, because a label may precede the control
     * it names, and a rewrite has to know every rename before it starts.
     */
    private function renameIds(\Dom\Element $element): void
    {
        $renamed = [];
        $this->rename($element, $renamed);

        if ([] !== $renamed) {
            $this->rewriteReferences($element, $renamed);
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

        foreach ($element->childNodes as $node) {
            if ($node instanceof \Dom\Element) {
                $this->rewriteReferences($node, $renamed);
            }
        }
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
