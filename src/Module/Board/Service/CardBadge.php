<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

/** A problem the board tile of a card shows. The case order is the order the tile draws them. */
enum CardBadge: string
{
    case ChecksFailed = 'checks-failed';
    case Conflict = 'conflict';
    case Blocked = 'blocked';

    public function translationKey(): string
    {
        return match ($this) {
            self::ChecksFailed => 'board.card.link.checks.failed',
            self::Conflict => 'board.card.link.mergeability.conflicting',
            self::Blocked => 'board.card.badge.blocked',
        };
    }
}
