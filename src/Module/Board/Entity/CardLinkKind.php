<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

/**
 * How one card reads its link to another. A row stores only `RelatesTo` or
 * `Blocks`, and the target of a `Blocks` row reads `BlockedBy`.
 */
enum CardLinkKind: string
{
    case RelatesTo = 'relates-to';
    case Blocks = 'blocks';
    case BlockedBy = 'blocked-by';

    public function inverse(): self
    {
        return match ($this) {
            self::RelatesTo => self::RelatesTo,
            self::Blocks => self::BlockedBy,
            self::BlockedBy => self::Blocks,
        };
    }
}
