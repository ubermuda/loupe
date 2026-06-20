<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\ValueObject\Anchor;

final class AnchorService
{
    private const int CONTEXT = 32;

    /** Number of leading/trailing characters of the captured context used to confirm a match. */
    private const int FINGERPRINT = 8;

    public function create(string $text, int $start, int $length): Anchor
    {
        // mb_strcut (not substr) keeps the byte-offset semantics resolve() relies on
        // while never splitting a multibyte UTF-8 character at a window boundary. A
        // raw substr can leave a dangling continuation byte (e.g. 0x80), which then
        // fails to persist into a UTF8 column (Postgres SQLSTATE[22021]).
        $quote = mb_strcut($text, $start, $length, 'UTF-8');
        $prefix = mb_strcut($text, max(0, $start - self::CONTEXT), min(self::CONTEXT, $start), 'UTF-8');
        $suffix = mb_strcut($text, $start + $length, self::CONTEXT, 'UTF-8');

        return new Anchor($quote, $prefix, $suffix, $start);
    }

    /**
     * Builds an anchor from the client-captured quote and surrounding context,
     * locating the offset server-side. The quote is a verbatim slice of $text
     * (the document's plain text), so it is found exactly — no offset arithmetic
     * crosses the wire, which sidesteps the byte/UTF-16 drift of the old path.
     *
     * An empty quote denotes an untargeted comment and yields an empty anchor.
     */
    public function fromSelection(string $text, string $quote, string $prefix, string $suffix): Anchor
    {
        if ('' === $quote) {
            return new Anchor('', '', '', 0);
        }

        $anchor = new Anchor($quote, $prefix, $suffix, 0);
        $offset = $this->locate($text, $anchor) ?? 0;

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
        while (false !== ($pos = strpos($text, $anchor->quote, $from))) {
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
        while (false !== ($pos = strpos($text, $anchor->quote, $from))) {
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
        $before = substr($text, max(0, $offset - self::CONTEXT), min(self::CONTEXT, $offset));
        $after = substr($text, $offset + strlen($anchor->quote), self::CONTEXT);
        $score = 0;
        if ('' !== $anchor->prefix && str_ends_with($before, substr($anchor->prefix, -self::FINGERPRINT))) {
            ++$score;
        }
        if ('' !== $anchor->suffix && str_starts_with($after, substr($anchor->suffix, 0, self::FINGERPRINT))) {
            ++$score;
        }

        return $score;
    }
}
