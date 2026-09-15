<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Inbox\Entity\InboxAsk;

/** The ask a session hands its items to, and whether this call opened it. */
final readonly class SessionAsk
{
    public function __construct(
        public InboxAsk $ask,
        public bool $opened,
    ) {
    }
}
