<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

enum InboxCardWaitTrigger: string
{
    case DocumentInReview = 'document-in-review';
    case RunBlocked = 'run-blocked';
    case RunGaveUp = 'run-gave-up';
    case RunWaitingForPerson = 'run-waiting-for-person';
    case PullRequestReady = 'pull-request-ready';
    case PullRequestFixStopped = 'pull-request-fix-stopped';
    case CardPaused = 'card-paused';

    public function isDocument(): bool
    {
        return self::DocumentInReview === $this;
    }

    public function isPullRequest(): bool
    {
        return self::PullRequestReady === $this || self::PullRequestFixStopped === $this;
    }

    public function isPause(): bool
    {
        return self::CardPaused === $this;
    }
}
