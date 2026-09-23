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

    public function translationKey(): string
    {
        return match ($this) {
            self::RelatesTo => 'board.card.linked_cards.kind.relates_to',
            self::Blocks => 'board.card.linked_cards.kind.blocks',
            self::BlockedBy => 'board.card.linked_cards.kind.blocked_by',
        };
    }
}
