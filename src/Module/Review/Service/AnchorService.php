<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\ValueObject\Anchor;

/**
 * Builds and relocates {@see Anchor}s against a document's plain text
 * (DocumentVersion::plainText()). Two entry points by use case:
 *
 *  - fromSelection(): the add-comment path. The browser captured the exact
 *    quote/prefix/suffix, so this only needs to find WHERE they sit
 *    (locate(), context-first — offsetHint isn't known yet) and record that
 *    offset. No offset crosses the wire; only the verbatim strings do.
 *  - create() + resolve(): the reanchoring path (see ReanchoringService). When
 *    a new version is created, resolve() finds the old quote in the new text —
 *    leaning on offsetHint to prefer the nearest occurrence — and create()
 *    rebuilds the anchor there.
 *
 * locate() vs resolve(): both pick the best occurrence of a repeated quote, but
 * locate() trusts the (fresh, exact) captured context alone, while resolve()
 * also weighs proximity to the previous version's offsetHint.
 *
 * Every offset, length and window here counts CHARACTERS, never bytes — the
 * same unit the browser slices textContent in (comment_anchor_controller's
 * #extractAnchor and #findRange). Mixing the two makes an anchor rebuilt by
 * create() cover a different span from the one the browser captured, and makes
 * the two sides rank the occurrences of a repeated quote differently.
 */
final class AnchorService
{
    /** Number of characters of surrounding text captured on each side of a quote. */
    private const int CONTEXT = 32;

    /** Number of leading/trailing characters of the captured context used to confirm a match. */
    private const int FINGERPRINT = 8;

    /** $start and $length are character offsets into $text, not byte offsets. */
    public function create(string $text, int $start, int $length): Anchor
    {
        $quote = mb_substr($text, $start, $length, 'UTF-8');
        $prefix = mb_substr($text, max(0, $start - self::CONTEXT), min(self::CONTEXT, $start), 'UTF-8');
        $suffix = mb_substr($text, $start + $length, self::CONTEXT, 'UTF-8');

        return new Anchor($quote, $prefix, $suffix, $start);
    }

    /**
     * Builds an anchor from the client-captured quote and surrounding context,
     * locating the offset server-side. The quote is a verbatim slice of $text
     * (the document's plain text), so it is found exactly — no offset arithmetic
     * crosses the wire.
     *
     * Returns null when the quote cannot be located in $text at all — e.g. the
     * document was revised in another tab between the client capturing the
     * selection and the comment being submitted. Callers must not fabricate an
     * offset-0 anchor in that case: it would claim a location that doesn't
     * exist, sort to the top of the sidebar, and silently fail to highlight.
     * Reuse the orphaned concept instead (see ReanchoringService).
     *
     * Callers must pass a real selection; an untargeted comment has no selection
     * and uses Anchor::unanchored() instead of this method.
     *
     * @param non-empty-string $quote
     */
    public function fromSelection(string $text, string $quote, string $prefix, string $suffix): ?Anchor
    {
        $offset = $this->locate($text, new Anchor($quote, $prefix, $suffix, 0));
        if (null === $offset) {
            return null;
        }

        return new Anchor($quote, $prefix, $suffix, $offset);
    }

    /**
     * Picks the occurrence of the quote whose surrounding context best matches
     * the captured prefix/suffix, breaking ties by earliest position. Unlike
     * resolve(), it does not lean on offsetHint — at add-time the captured
     * context is exact, so it is the most reliable disambiguator.
     */
    private function locate(string $text, Anchor $anchor): ?int
    {
        $offsets = [];
        $from = 0;
        while (false !== ($pos = mb_strpos($text, $anchor->quote, $from, 'UTF-8'))) {
            $offsets[] = $pos;
            $from = $pos + 1;
        }
        if ([] === $offsets) {
            return null;
        }

        usort($offsets, fn (int $a, int $b): int => [$this->contextScore($text, $b, $anchor), $a]
            <=> [$this->contextScore($text, $a, $anchor), $b]);

        return $offsets[0];
    }

    public function resolve(string $text, Anchor $anchor): ?int
    {
        if ('' === $anchor->quote) {
            return null;
        }

        $offsets = [];
        $from = 0;
        while (false !== ($pos = mb_strpos($text, $anchor->quote, $from, 'UTF-8'))) {
            $offsets[] = $pos;
            $from = $pos + 1;
        }
        if ([] === $offsets) {
            return null;
        }

        usort($offsets, function (int $a, int $b) use ($anchor, $text): int {
            $score = fn (int $o): int => abs($o - $anchor->offsetHint)
                - ($this->contextScore($text, $o, $anchor) * self::CONTEXT);

            return $score($a) <=> $score($b);
        });

        return $offsets[0];
    }

    private function contextScore(string $text, int $offset, Anchor $anchor): int
    {
        $before = mb_substr($text, max(0, $offset - self::CONTEXT), min(self::CONTEXT, $offset), 'UTF-8');
        $after = mb_substr($text, $offset + mb_strlen($anchor->quote, 'UTF-8'), self::CONTEXT, 'UTF-8');
        $score = 0;
        if ('' !== $anchor->prefix && str_ends_with($before, mb_substr($anchor->prefix, -self::FINGERPRINT, null, 'UTF-8'))) {
            ++$score;
        }
        if ('' !== $anchor->suffix && str_starts_with($after, mb_substr($anchor->suffix, 0, self::FINGERPRINT, 'UTF-8'))) {
            ++$score;
        }

        return $score;
    }
}
