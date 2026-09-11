<?php

declare(strict_types=1);

namespace App\Module\Board;

/** The outbox event types this module produces. */
final class BoardEventType
{
    public const string CARD_MOVED = 'board.card_moved';

    private function __construct()
    {
    }
}
