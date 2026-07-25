<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Service\AccountDeletionEmailSender;
use Psr\Log\LoggerInterface;

final readonly class RequestAccountDeletionHandler
{
    public function __construct(
        private AccountDeletionEmailSender $emailSender,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RequestAccountDeletionCommand $command): void
    {
        // A retry or double-click while a link is still live must not mint a
        // new token: that silently invalidates the one already emailed and
        // sends an unbounded stream of duplicate messages. The still-valid
        // original link already does the job.
        if ($command->user->hasActiveAccountDeletionToken()) {
            return;
        }

        $this->emailSender->send($command->user);

        $this->logger->info('account.deletion.requested', [
            'userId' => (string) $command->user->id,
        ]);
    }
}
