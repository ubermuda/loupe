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
 * Every offset, length and window this class exposes counts CHARACTERS, never
 * bytes — matching how the browser slices textContent (comment_anchor_controller's
 * #extractAnchor and #findRange), up to the caveat that JS counts UTF-16 code
 * units, so the two agree only within the Basic Multilingual Plane. Mixing the
 * units makes an anchor rebuilt by create() cover a different span from the one
 * the browser captured, and makes the two sides rank the occurrences of a
 * repeated quote differently.
 *
 * Searching and scoring nonetheless happen in byte space internally, converted to
 * characters once at the end (see characterOffsets) — mb_* seeking is linear in the
 * offset, and doing it per occurrence per comparison makes resolution quadratic.
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
     * Builds an anchor for a caller that can only name the passage verbatim and
     * has no selection to capture context from — an agent pointing at a span it
     * never rendered.
     *
     * The context window is sliced out of $text here rather than supplied, so the
     * stored anchor is shaped exactly like one a human selection produced. Storing
     * the empty prefix/suffix the caller has instead would leave both resolvers
     * with no fingerprint to rank a repeated quote by, and each would silently
     * settle on the earliest occurrence.
     *
     * Returns null when the quote does not appear in $text at all.
     *
     * @param non-empty-string $quote
     */
    public function fromQuote(string $text, string $quote): ?Anchor
    {
        $offset = $this->locate($text, new Anchor($quote, '', '', 0));
        if (null === $offset) {
            return null;
        }

        return $this->create($text, $offset, mb_strlen($quote, 'UTF-8'));
    }

    /**
     * Picks the occurrence of the quote whose surrounding context best matches
     * the captured prefix/suffix, breaking ties by earliest position. Unlike
     * resolve(), it does not lean on offsetHint — at add-time the captured
     * context is exact, so it is the most reliable disambiguator.
     */
    private function locate(string $text, Anchor $anchor): ?int
    {
        $byteOffsets = $this->occurrences($text, $anchor->quote);
        if ([] === $byteOffsets) {
            return null;
        }
        $characterOffsets = $this->characterOffsets($text, $byteOffsets);

        usort($byteOffsets, fn (int $a, int $b): int => [$this->contextScore($text, $b, $anchor), $a]
            <=> [$this->contextScore($text, $a, $anchor), $b]);

        return $characterOffsets[$byteOffsets[0]];
    }

    public function resolve(string $text, Anchor $anchor): ?int
    {
        if ('' === $anchor->quote) {
            return null;
        }

        $byteOffsets = $this->occurrences($text, $anchor->quote);
        if ([] === $byteOffsets) {
            return null;
        }
        $characterOffsets = $this->characterOffsets($text, $byteOffsets);

        usort($byteOffsets, function (int $a, int $b) use ($anchor, $text, $characterOffsets): int {
            // offsetHint is a character offset, so the distance term compares against
            // the converted offset rather than the byte one it is sorting.
            $score = fn (int $o): int => abs($characterOffsets[$o] - $anchor->offsetHint)
                - ($this->contextScore($text, $o, $anchor) * self::CONTEXT);

            return $score($a) <=> $score($b);
        });

        return $characterOffsets[$byteOffsets[0]];
    }

    /**
     * Ascending BYTE offsets of every occurrence of $quote, overlaps included.
     *
     * Searching in byte space finds exactly the occurrences a character-space search
     * would: a UTF-8 lead byte can never equal a continuation byte, so a byte match
     * cannot start mid-character.
     *
     * @return list<int>
     */
    private function occurrences(string $text, string $quote): array
    {
        $byteOffsets = [];
        $from = 0;
        while (false !== ($at = strpos($text, $quote, $from))) {
            $byteOffsets[] = $at;
            $from = $at + 1;
        }

        return $byteOffsets;
    }

    /**
     * Maps each byte offset to its character offset, for ASCENDING $byteOffsets.
     *
     * One left-to-right pass over the text converts the whole set. Converting each
     * offset on its own — mb_strlen(substr($text, 0, $offset)) — re-scans from the
     * start every time, which is what makes anchor resolution quadratic in document
     * length: seconds of CPU on a 200 KB document, inside a synchronous revise request.
     *
     * @param list<int> $byteOffsets
     *
     * @return array<int, int>
     */
    private function characterOffsets(string $text, array $byteOffsets): array
    {
        $map = [];
        $scannedBytes = 0;
        $characters = 0;
        foreach ($byteOffsets as $byteOffset) {
            $characters += mb_strlen(substr($text, $scannedBytes, $byteOffset - $scannedBytes), 'UTF-8');
            $scannedBytes = $byteOffset;
            $map[$byteOffset] = $characters;
        }

        return $map;
    }

    /**
     * How much of the captured context still surrounds the occurrence at $byteOffset:
     * one point for the prefix, one for the suffix.
     *
     * The fingerprints are the last/first FINGERPRINT *characters* of the captured
     * context — that character count is what the browser's #findRange weighs — but
     * they are compared byte-wise against the text. Byte equality implies character
     * equality in UTF-8, and it keeps this O(1): slicing $text with mb_substr would
     * scan from byte 0 on every call, and every call sits inside a sort comparator.
     */
    private function contextScore(string $text, int $byteOffset, Anchor $anchor): int
    {
        $score = 0;

        $before = mb_substr($anchor->prefix, -self::FINGERPRINT, null, 'UTF-8');
        $beforeLength = \strlen($before);
        if ('' !== $before
            && $byteOffset >= $beforeLength
            && $before === substr($text, $byteOffset - $beforeLength, $beforeLength)
        ) {
            ++$score;
        }

        $after = mb_substr($anchor->suffix, 0, self::FINGERPRINT, 'UTF-8');
        $quoteEnd = $byteOffset + \strlen($anchor->quote);
        if ('' !== $after && $after === substr($text, $quoteEnd, \strlen($after))) {
            ++$score;
        }

        return $score;
    }
}
