<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** Why a worker run ended the way it did, as the bridge reports it with an outcome. */
enum WorkerRunReason: string
{
    case Done = 'done';
    case CardLeft = 'card-left';
    case WaitingChecks = 'waiting-checks';
    case NotApproved = 'not-approved';
    case ApprovalStale = 'approval-stale';
    case Stacked = 'stacked';
    case Conflicting = 'conflicting';
    case NotBehind = 'not-behind';
    case NoDesign = 'no-design';
    case OpenPullRequest = 'open-pull-request';
    case NoPullRequest = 'no-pull-request';
    case ToolUnavailable = 'tool-unavailable';
    case WorktreeFailed = 'worktree-failed';
    case MergeRefused = 'merge-refused';
    case NeedsPerson = 'needs-person';
    case WorkRemains = 'work-remains';
    case Other = 'other';

    /** A newer bridge can send a code this server does not know, and the run keeps it as Other. */
    public static function fromReported(?string $code): ?self
    {
        $code = trim($code ?? '');
        if ('' === $code) {
            return null;
        }

        return self::tryFrom($code) ?? self::Other;
    }
}
