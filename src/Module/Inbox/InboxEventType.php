<?php

declare(strict_types=1);

namespace App\Module\Inbox;

/** The outbox event types this module produces, and the actors that cause them. */
final class InboxEventType
{
    public const string ASK_CLOSED = 'inbox.ask_closed';

    /** The owner closed the last blocking item. */
    public const string ACTOR_HUMAN = 'human';

    /** An agent withdrew the last blocking item, or its cards finished. */
    public const string ACTOR_AGENT = 'agent';

    private function __construct()
    {
    }
}
