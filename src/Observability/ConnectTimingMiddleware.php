<?php

declare(strict_types=1);

namespace App\Observability;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

#[AsMiddleware]
final readonly class ConnectTimingMiddleware implements Middleware
{
    public function __construct(
        private RequestTimeline $timeline,
    ) {
    }

    #[\Override]
    public function wrap(Driver $driver): Driver
    {
        return new ConnectTimingDriver($driver, $this->timeline);
    }
}
