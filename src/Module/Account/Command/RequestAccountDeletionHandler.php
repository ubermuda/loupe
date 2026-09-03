<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Service\AccountDeletionEmailSender;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class RequestAccountDeletionHandler
{
    public function __construct(
        private AccountDeletionEmailSender $emailSender,
        private Auditor $auditor,
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

        $this->auditor->record(
            'account.deletion_requested',
            AuditOutcome::Success,
            ['userId' => (string) $command->user->id],
            new AuditSubject('user', (string) $command->user->id),
        );
    }
}
