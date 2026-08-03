<?php

declare(strict_types=1);

namespace App\Module\Review\ValueObject;

/**
 * Which side of a two-version comparison a piece of text belongs to.
 *
 * The three cases are exhaustive and non-overlapping, which is what lets a
 * diff be read back as either of the sources it was built from: unchanged plus
 * inserted is the new version, unchanged plus deleted is the old one.
 */
enum DiffKind: string
{
    case Unchanged = 'unchanged';
    case Inserted = 'inserted';
    case Deleted = 'deleted';

    /** Whether text of this kind is part of the old version's source. */
    public function isInOld(): bool
    {
        return self::Inserted !== $this;
    }

    /** Whether text of this kind is part of the new version's source. */
    public function isInNew(): bool
    {
        return self::Deleted !== $this;
    }
}
