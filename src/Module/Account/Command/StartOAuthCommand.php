<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Entity\SocialProvider;

final readonly class StartOAuthCommand
{
    public function __construct(
        public SocialProvider $provider,
    ) {
    }
}
