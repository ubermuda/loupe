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

    /** The line that says since when the state holds, with a %time% placeholder. */
    public function sinceKey(): string
    {
        return 'board.card_state.since.'.str_replace('-', '_', $this->value);
    }

    /** The label of the field that holds the start time. */
    public function sinceLabelKey(): string
    {
        return 'board.card_state.since_label.'.str_replace('-', '_', $this->value);
    }

    /** The label of the field that says what clears the state. */
    public function remedyLabelKey(): string
    {
        return 'board.card_state.remedy_label.'.str_replace('-', '_', $this->value);
    }
}
