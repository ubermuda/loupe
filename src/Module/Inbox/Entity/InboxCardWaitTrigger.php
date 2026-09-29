<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

enum InboxCardWaitTrigger: string
{
    case DocumentInReview = 'document-in-review';
    case RunBlocked = 'run-blocked';
    case RunGaveUp = 'run-gave-up';
    case RunWaitingForPerson = 'run-waiting-for-person';

    public function isDocument(): bool
    {
        return self::DocumentInReview === $this;
    }
}
