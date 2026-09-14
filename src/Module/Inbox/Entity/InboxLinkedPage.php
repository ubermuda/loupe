<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

/** A page outside the inbox that lists the items linked to it, and that a response from it returns to. */
enum InboxLinkedPage: string
{
    case Card = 'card';
    case Document = 'document';
}
