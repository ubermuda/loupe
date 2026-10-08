<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

enum ForgeUserConnectionState: string
{
    case None = 'none';
    case Connected = 'connected';
    case Expired = 'expired';
}
