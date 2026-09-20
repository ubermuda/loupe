<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use Psr\Http\Message\ServerRequestInterface;

final readonly class StartDeviceAuthorizationCommand
{
    public function __construct(
        public ServerRequestInterface $request,
    ) {
    }
}
