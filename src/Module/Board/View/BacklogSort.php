<?php

declare(strict_types=1);

namespace App\Module\Board\View;

/** The columns the Backlog page sorts by. */
enum BacklogSort: string
{
    case Created = 'created';
    case Type = 'type';
    case Epic = 'epic';

    /** The direction a first click on the column header asks for. */
    public function firstDirection(): BacklogDirection
    {
        return self::Created === $this ? BacklogDirection::Desc : BacklogDirection::Asc;
    }
}
