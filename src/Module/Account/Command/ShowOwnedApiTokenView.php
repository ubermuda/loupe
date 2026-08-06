<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Entity\ApiToken;

final readonly class ShowOwnedApiTokenView
{
    public function __construct(
        public ?ApiToken $token,
    ) {
    }
}
