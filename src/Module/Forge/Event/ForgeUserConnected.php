<?php

declare(strict_types=1);

namespace App\Module\Forge\Event;

use Symfony\Component\Uid\Uuid;

/** A user connected a forge account, or connected it again after the connection expired. */
final readonly class ForgeUserConnected
{
    public function __construct(
        public Uuid $userId,
    ) {
    }
}
