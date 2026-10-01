<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

/** Why an open pull request on the default branch is or is not in the sync line. */
enum PullRequestSyncStatus: string
{
    case SyncFailed = 'sync-failed';
    case Conflicts = 'conflicts';
    case WaitsForApproval = 'waits-for-approval';
    case SyncedChecksRunning = 'synced-checks-running';
    case WaitsTurn = 'waits-turn';
}
