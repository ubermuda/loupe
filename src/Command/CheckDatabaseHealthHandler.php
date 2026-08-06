<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final readonly class CheckDatabaseHealthHandler
{
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CheckDatabaseHealthCommand $command): CheckDatabaseHealthView
    {
        try {
            $this->connection->executeQuery('SELECT 1'); // @translation-check-ignore
        } catch (\Throwable $e) {
            $this->logger->error('health.database_unreachable', ['exception' => $e]);

            return new CheckDatabaseHealthView(false);
        }

        return new CheckDatabaseHealthView(true);
    }
}
