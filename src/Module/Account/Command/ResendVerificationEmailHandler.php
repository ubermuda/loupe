<?php

namespace App\Module\Account\Command;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\VerificationEmailSender;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;

final readonly class ResendVerificationEmailHandler
{
    public function __construct(
        private UserRepository $users,
        private VerificationEmailSender $verificationEmailSender,
        private Auditor $auditor,
    ) {
    }

    /**
     * Completes silently for unknown or already-verified accounts, and on
     * mail-transport failure — the response must not reveal account state.
     */
    public function __invoke(ResendVerificationEmailCommand $command): void
    {
        $user = $this->users->findOneByEmail($command->email);

        if (!$user instanceof User || $user->isVerified()) {
            return;
        }

        try {
            $this->verificationEmailSender->send($user);
        } catch (\Throwable) {
        }

        // Keyed on the internal id and written only where a user resolved, so
        // the record cannot answer "does this account exist" for anyone.
        $this->auditor->record(
            'account.email_verification.resent',
            AuditOutcome::Success,
            ['userId' => (string) $user->id],
            new AuditSubject('user', (string) $user->id),
        );
    }
}
