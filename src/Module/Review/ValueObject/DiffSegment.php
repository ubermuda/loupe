<?php

declare(strict_types=1);

namespace App\Module\Review\ValueObject;

/**
 * A run of characters within one line of a diff, tagged with the side it
 * belongs to. Text is the raw Markdown source — never HTML-escaped, so the
 * template escapes it once and reconstruction returns the stored source
 * byte-for-byte.
 */
final readonly class DiffSegment
{
    public function __construct(
        public DiffKind $kind,
        public string $text,
    ) {
    }
}
