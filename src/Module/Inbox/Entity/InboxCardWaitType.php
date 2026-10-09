<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

/** What a card waits on. The page turns the type and the reason into a sentence. */
enum InboxCardWaitType: string
{
    case Document = 'document';
    case PullRequest = 'pull-request';
    case WorkerRun = 'worker-run';
    case CardPause = 'card-pause';
}
