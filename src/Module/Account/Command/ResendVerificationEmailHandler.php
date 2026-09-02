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

        $outcome = AuditOutcome::Success;

        try {
            $this->verificationEmailSender->send($user);
        } catch (\Throwable) {
            // The sender flushes the new token before it mails, so a failure
            // still rotates the token and invalidates the old link. The record
            // has to say the resend broke, or the trail claims a delivery the
            // user never got. The transport error itself is the mailer's to log.
            $outcome = AuditOutcome::Failed;
        }

        // Keyed on the internal id and written only where a user resolved, so
        // the record cannot answer "does this account exist" for anyone.
        $this->auditor->record(
            'account.email_verification_resent',
            $outcome,
            ['userId' => (string) $user->id],
            new AuditSubject('user', (string) $user->id),
        );
    }
}
