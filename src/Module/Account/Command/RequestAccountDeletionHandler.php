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
        $this->emailSender->send($command->user);

        $this->logger->info('account.deletion.requested', [
            'userId' => (string) $command->user->id,
        ]);
    }
}
