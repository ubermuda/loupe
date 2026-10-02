<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** A work request is open until a bridge claims it. A claimed request settles, or opens again when its lease lapses. */
enum WorkRequestState: string
{
    case Open = 'open';

    case Claimed = 'claimed';

    case Done = 'done';

    case Refused = 'refused';

    case Expired = 'expired';

    case Cancelled = 'cancelled';

    /** The states the unique index of a card and a kind covers. */
    public function isLive(): bool
    {
        return self::Open === $this || self::Claimed === $this;
    }
}
