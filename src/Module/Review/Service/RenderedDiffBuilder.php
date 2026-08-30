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
 * A change is a run of marks with no unchanged text between them, which is the
 * grouping the eye already makes: a deleted heading and the paragraph under it
 * are two block wrappers and one change, and a reworded phrase is a `del` next
 * to an `ins` and one change. DocumentDiff's lines do not describe this, since
 * a paragraph reflowed across them renders as one block.
 *
 * Runs over the renderer's output rather than inside it, so every other caller
 * of the renderer keeps the HTML it has today.
 */
final readonly class RenderedDiffBuilder
{
    private const string HUNK_CLASS = 'lp-diff__hunk';

    /** The fieldset DecisionBlockService mints, which a diff shows but cannot answer. */
    private const string DECISION_CLASS = 'lp-decision';

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function build(string $html): RenderedDiff
    {
        $body = \Dom\HTMLDocument::createFromString($html, \LIBXML_NOERROR, 'UTF-8')->body
            ?? throw new \RuntimeException('Rendered diff parsed to a document with no body.');

        $count = 0;
        $open = false;
        $this->visit($body, false, $count, $open);

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
            }

            $this->visit($node, $insideMark || $isMark, $count, $open);
        }
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

    private function openHunk(\Dom\Element $mark, int $number): void
    {
        $mark->setAttribute('id', 'diff-hunk-'.$number);
        $mark->setAttribute('tabindex', '-1');
        $mark->setAttribute('data-diff-navigation-target', 'hunk');
        $mark->classList->add(self::HUNK_CLASS);
    }
}
