<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

/** The title of a card that a widget note creates: its first non-blank line. */
final class FeedbackCardTitle
{
    private const int LENGTH = 80;

    public static function of(string $body): string
    {
        $lines = preg_split('/\R/u', $body) ?: [$body];
        $line = array_find($lines, static fn (string $line): bool => '' !== trim($line)) ?? $body;

        return trim(mb_substr(trim($line), 0, self::LENGTH));
    }
}
