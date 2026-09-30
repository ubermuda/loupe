<?php

declare(strict_types=1);

namespace App\Module\Bridge\Scheduler;

use App\Module\Bridge\Command\ExpireBridgeCommandsCommand;
use App\Module\Bridge\Command\ExpireBridgeCommandsHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/** Expires the commands no bridge settled in time, every minute. */
#[AsCronTask('%app.bridge.command_expiry_schedule%')]
final readonly class ExpireBridgeCommandsTask
{
    public function __construct(
        private ExpireBridgeCommandsHandler $expireBridgeCommands,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        $expired = ($this->expireBridgeCommands)(new ExpireBridgeCommandsCommand());

        // A tick that finds nothing is the common case, and a line per minute would bury the worker log.
        if ($expired > 0) {
            $this->logger->info('bridge.commands_expired', ['expired' => $expired]);
        }
    }
}
