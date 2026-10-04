<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

/** A problem or a workflow state the board tile of a card shows. The case order is the order the tile draws them. */
enum CardBadge: string
{
    case ChecksFailed = 'checks-failed';
    case Conflict = 'conflict';
    case Paused = 'paused';
    case Unmanaged = 'unmanaged';

    public function translationKey(): string
    {
        return match ($this) {
            self::ChecksFailed => 'board.card.link.checks.failed',
            self::Conflict => 'board.card.link.mergeability.conflicting',
            self::Paused => 'board.card.badge.paused',
            self::Unmanaged => 'board.card.badge.unmanaged',
        };
    }

    /** The lp-status-chip modifier of the badge. */
    public function chipModifier(): string
    {
        return match ($this) {
            self::ChecksFailed, self::Conflict => 'failed',
            self::Paused => 'pending',
            self::Unmanaged => 'neutral',
        };
    }
}
