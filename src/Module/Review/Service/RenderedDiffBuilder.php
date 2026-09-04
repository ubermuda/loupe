<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\ValueObject\RenderedDiff;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Adapts {@see MarkdownRenderer::renderDiff()} output for the review page's
 * document pane: it numbers the jump targets, names each mark for a reader who
 * is served neither the tint nor the strike-through, and makes the document's
 * own decision controls inert.
 *
 * A change is a run of marks with nothing unchanged drawn between them, which
 * is the grouping the eye already makes: a deleted heading and the paragraph
 * under it are two block wrappers and one change, and a reworded phrase is a
 * `del` next to an `ins` and one change. Unchanged text ends a run, and so does
 * an unchanged element that draws itself, such as a rule or an image, which a
 * reader sees between the two changes as plainly as a word.
 * DocumentDiff's lines do not describe this, since a paragraph reflowed across
 * them renders as one block.
 *
 * Runs over the renderer's output rather than inside it, so every other caller
 * of the renderer keeps the HTML it has today.
 */
final readonly class RenderedDiffBuilder
{
    private const string HUNK_CLASS = 'lp-diff__hunk';

    /** The fieldset DecisionBlockService mints, which a diff shows but cannot answer. */
    private const string DECISION_CLASS = 'lp-decision';

    /** Carries a run's character offset into the newer version's plain text. */
    public const string OFFSET_ATTRIBUTE = 'data-diff-offset';

    /**
     * Elements whose only legal children are table structure. The HTML parser
     * moves anything else out of the table, which would reorder the pane's text
     * and put every offset after it wrong, so text in one is left unwrapped.
     */
    private const array TABLE_STRUCTURE = ['table', 'thead', 'tbody', 'tfoot', 'tr'];

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param ?string $newPlainText the newer version's DocumentVersion::plainText(),
     *                              when a comment made on this diff would anchor
     *                              against it. Null leaves the pane unanchorable.
     */
    public function build(string $html, ?string $newPlainText = null): RenderedDiff
    {
        $body = \Dom\HTMLDocument::createFromString($html, \LIBXML_NOERROR, 'UTF-8')->body
            ?? throw new \RuntimeException('Rendered diff parsed to a document with no body.');

        $count = 0;
        $open = false;
        $this->visit($body, false, $count, $open);

        if (null !== $newPlainText) {
            $this->stampOffsets($body, $newPlainText);
        }

        return new RenderedDiff($body->innerHTML, $count);
    }

    /**
     * @param int  $count number of runs seen so far
     * @param bool $open  whether the previous node continued a run
     */
    private function visit(\Dom\Node $parent, bool $insideMark, int &$count, bool &$open): void
    {
        foreach ($parent->childNodes as $node) {
            if ($node instanceof \Dom\Text) {
                // Whitespace alone keeps the run open: two blocks removed together
                // are separated by nothing but the newline between their tags.
                if (!$insideMark && '' !== trim($node->data)) {
                    $open = false;
                }

                continue;
            }

            if (!$node instanceof \Dom\Element) {
                continue;
            }

            if ($node->classList->contains(self::DECISION_CLASS)) {
                // The answer belongs to one version, and a diff is looking at two.
                $node->setAttribute('disabled', 'disabled');
            }

            $isMark = !$insideMark && $node->classList->contains(MarkdownRenderer::DIFF_MARK_CLASS);
            if ($isMark) {
                $this->label($node);
                if (!$open) {
                    $open = true;
                    $this->openHunk($node, ++$count);
                }
            } elseif (!$insideMark && $this->drawsItsOwnContent($node)) {
                $open = false;
            }

            $this->visit($node, $insideMark || $isMark, $count, $open);
        }
    }

    /**
     * Whether an unmarked element puts something on the page by itself, which
     * separates two runs the way unchanged text does. A rule, an image and a
     * radio all do. An element that merely happens to be empty does not, and
     * its box is not content the document wrote.
     *
     * The test is voidness, read from the serializer rather than from a list of
     * tag names that would go stale: a void element takes no children, so what
     * it draws can only be itself. An element that has children is never void,
     * and its own descendants answer for it during the walk.
     */
    private function drawsItsOwnContent(\Dom\Element $element): bool
    {
        return 0 === $element->childNodes->length
            && !str_ends_with($element->outerHTML, '</'.strtolower($element->tagName).'>');
    }

    /**
     * `data-diff-label` is read back by CSS generated content, so the name
     * reaches the accessibility tree without joining the text a reader copies
     * out of the page.
     */
    private function label(\Dom\Element $mark): void
    {
        $mark->setAttribute('data-diff-label', $this->translator->trans(
            $mark->classList->contains(MarkdownRenderer::DIFF_MARK_CLASS.'--inserted')
                ? 'review.document.diff.mark.inserted'
                : 'review.document.diff.mark.deleted',
        ));
    }

    /**
     * Wraps each run of text the newer version also holds in a span carrying
     * that run's character offset into the newer version's plain text.
     *
     * A comment anchor is a verbatim slice of that text, and this pane holds two
     * versions at once, so the browser cannot slice it out of the pane alone.
     * The offsets tell it which runs join up. Deleted text gets none, and so
     * does a run the two texts no longer agree on, which is what makes a
     * selection over either one refusable.
     */
    private function stampOffsets(\Dom\Element $body, string $newPlainText): void
    {
        $nodes = [];
        $this->collectNewSideText($body, false, $nodes);

        $stamps = [];
        $bytes = 0;
        $characters = 0;
        foreach ($nodes as $node) {
            $text = $node->data;
            if (substr($newPlainText, $bytes, \strlen($text)) !== $text) {
                // A changed block is wrapped in a mark of its own, which adds
                // newlines the newer version never had. Anything else means the
                // two texts have parted company, so the rest goes unstamped.
                if ('' !== trim($text)) {
                    break;
                }

                continue;
            }

            $parent = $node->parentNode;
            if ($parent instanceof \Dom\Element && !in_array(strtolower($parent->tagName), self::TABLE_STRUCTURE, true)) {
                $stamps[] = [$node, $characters];
            }

            $characters += mb_strlen($text, 'UTF-8');
            $bytes += \strlen($text);
        }

        $document = $body->ownerDocument
            ?? throw new \RuntimeException('The rendered diff body left its document.');

        foreach ($stamps as [$node, $offset]) {
            $span = $document->createElement('span');
            $span->setAttribute(self::OFFSET_ATTRIBUTE, (string) $offset);
            $node->parentNode?->replaceChild($span, $node);
            $span->appendChild($node);
        }
    }

    /**
     * Every text node the newer version also holds, in document order.
     *
     * @param list<\Dom\Text> $nodes
     */
    private function collectNewSideText(\Dom\Node $parent, bool $deleted, array &$nodes): void
    {
        foreach ($parent->childNodes as $node) {
            if ($node instanceof \Dom\Text) {
                if (!$deleted) {
                    $nodes[] = $node;
                }

                continue;
            }

            if (!$node instanceof \Dom\Element) {
                continue;
            }

            $this->collectNewSideText(
                $node,
                $deleted || $node->classList->contains(MarkdownRenderer::DIFF_MARK_CLASS.'--deleted'),
                $nodes,
            );
        }
    }

    private function openHunk(\Dom\Element $mark, int $number): void
    {
        $mark->setAttribute('id', 'diff-hunk-'.$number);
        $mark->setAttribute('tabindex', '-1');
        $mark->setAttribute('data-diff-navigation-target', 'hunk');
        $mark->classList->add(self::HUNK_CLASS);
    }
}
