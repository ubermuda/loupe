<?php

declare(strict_types=1);

namespace App\Module\Review\ValueObject;

/**
 * One source line of a diff.
 *
 * A rewritten line appears twice — once deleted, once inserted — so that word
 * marks can be shown on both sides. Its segments are therefore constrained by
 * the line's own kind: a deleted line holds only unchanged and deleted
 * segments, an inserted line only unchanged and inserted ones. Without that
 * constraint the same unchanged run would be counted twice when reading a
 * version back out of the diff.
 */
final readonly class DiffLine
{
    /** @param list<DiffSegment> $segments */
    public function __construct(
        public DiffKind $kind,
        public array $segments,
    ) {
    }

    public static function unchanged(string $text): self
    {
        return new self(DiffKind::Unchanged, [new DiffSegment(DiffKind::Unchanged, $text)]);
    }

    public static function inserted(string $text): self
    {
        return new self(DiffKind::Inserted, [new DiffSegment(DiffKind::Inserted, $text)]);
    }

    public static function deleted(string $text): self
    {
        return new self(DiffKind::Deleted, [new DiffSegment(DiffKind::Deleted, $text)]);
    }
}
