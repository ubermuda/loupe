<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\ValueObject\Anchor;

final class AnchorService
{
    private const int CONTEXT = 32;

    public function create(string $text, int $start, int $length): Anchor
    {
        $quote = substr($text, $start, $length);
        $prefix = substr($text, max(0, $start - self::CONTEXT), min(self::CONTEXT, $start));
        $suffix = substr($text, $start + $length, self::CONTEXT);

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
        if ('' !== $anchor->prefix && str_ends_with($before, substr($anchor->prefix, -8))) {
            ++$score;
        }
        if ('' !== $anchor->suffix && str_starts_with($after, substr($anchor->suffix, 0, 8))) {
            ++$score;
        }

        return $score;
    }
}
