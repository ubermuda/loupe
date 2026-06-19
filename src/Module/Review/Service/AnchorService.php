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
