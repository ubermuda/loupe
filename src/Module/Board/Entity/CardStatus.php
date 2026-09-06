<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

/** The column a card sits in on the board. */
enum CardStatus: string
{
    case Backlog = 'backlog';
    case Next = 'next';
    case InProgress = 'in-progress';
    case Done = 'done';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
