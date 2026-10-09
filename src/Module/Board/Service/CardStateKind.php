<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

/** What a card needs right now. The case order is the order of precedence: the first state that applies wins. */
enum CardStateKind: string
{
    case Stuck = 'stuck';
    case NeedsYou = 'needs-you';
    case Working = 'working';
    case Waiting = 'waiting';

    public function translationKey(): string
    {
        return match ($this) {
            self::Stuck => 'board.card_state.stuck',
            self::NeedsYou => 'board.card_state.needs_you',
            self::Working => 'board.card_state.working',
            self::Waiting => 'board.card_state.waiting',
        };
    }
}
