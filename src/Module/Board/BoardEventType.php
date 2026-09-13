<?php

declare(strict_types=1);

namespace App\Module\Board;

/** The outbox event types this module produces. */
final class BoardEventType
{
    public const string CARD_MOVED = 'board.card_moved';
    public const string COLUMN_RENAMED = 'board.column_renamed';
    public const string COLUMN_DELETED = 'board.column_deleted';

    private function __construct()
    {
    }
}
