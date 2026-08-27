<?php

declare(strict_types=1);

namespace App\Module\Review\Entity;

enum Verdict: string
{
    case Approved = 'approved';
    case ChangesRequested = 'changes-requested';

    /**
     * The reviewer taking their verdict back. It is a row of its own rather than a
     * flag on the one it undoes, because reviews are an append-only log: mutating
     * the earlier row would lose which verdict was withdrawn, and deleting it would
     * lose that a verdict was ever given.
     */
    case Withdrawn = 'withdrawn';

    /** What the document reads as while this is the standing verdict on its current version. */
    public function documentStatus(): DocumentStatus
    {
        return match ($this) {
            self::Approved => DocumentStatus::Approved,
            self::ChangesRequested => DocumentStatus::ChangesRequested,
            self::Withdrawn => DocumentStatus::InReview,
        };
    }
}
