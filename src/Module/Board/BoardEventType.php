<?php

declare(strict_types=1);

namespace App\Module\Board;

/** The outbox event types this module produces. */
final class BoardEventType
{
    public const string CARD_MOVED = 'board.card_moved';
    public const string COLUMN_RENAMED = 'board.column_renamed';
    public const string COLUMN_DELETED = 'board.column_deleted';

    /** Board decides these from the state of a card's pull request. */
    public const string PULL_REQUEST_FIX_REQUESTED = 'pull_request.fix_requested';
    public const string PULL_REQUEST_READY_TO_MERGE = 'pull_request.ready_to_merge';

    private function __construct()
    {
    }
}
