<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

enum InboxAskOrigin: string
{
    case Agent = 'agent';
    case Loupe = 'loupe';
}
