<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** A group of facts that a condition reads. */
enum FactKey: string
{
    case Slot = 'slot';
    case CardType = 'card-type';
    case Blockers = 'blockers';
    case Parent = 'parent';
    case Children = 'children';
    case Documents = 'documents';
    case ParentDocuments = 'parent-documents';
    case PullRequest = 'pull-request';
    case PullRequests = 'pull-requests';
    case WorkRequests = 'work-requests';
    case Refusal = 'refusal';
    case WorkerRuns = 'worker-runs';
    case ParentWork = 'parent-work';
    case ParentSlot = 'parent-slot';
}
