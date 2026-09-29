<?php

declare(strict_types=1);

namespace App\Observability;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * @phpstan-import-type Params from \Doctrine\DBAL\DriverManager
 */
final class ConnectTimingDriver extends AbstractDriverMiddleware
{
    public function __construct(
        Driver $driver,
        private readonly RequestTimeline $timeline,
    ) {
        parent::__construct($driver);
    }

    /**
     * @param Params $params
     */
    #[\Override]
    public function connect(#[\SensitiveParameter] array $params): DriverConnection
    {
        return $this->timeline->span('db.connect', fn (): DriverConnection => parent::connect($params));
    }
}
