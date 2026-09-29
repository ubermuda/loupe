<?php

declare(strict_types=1);

namespace App\Module\Board\View;

/** The orders the Backlog page offers. Rank is the order the board keeps. */
enum BacklogSort: string
{
    case Rank = 'rank';
    case Newest = 'newest';
    case Oldest = 'oldest';
    case Updated = 'updated';

    public function translationKey(): string
    {
        return 'board.backlog.sort.'.$this->value;
    }
}
