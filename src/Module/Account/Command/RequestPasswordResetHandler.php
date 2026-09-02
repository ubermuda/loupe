<?php

namespace App\Module\Account\Command;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\PasswordResetEmailSender;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;

final readonly class RequestPasswordResetHandler
{
    public function __construct(
        private UserRepository $users,
        private PasswordResetEmailSender $passwordResetEmailSender,
        private Auditor $auditor,
    ) {
    }

    /**
     * Always completes silently: whether the account exists, already has an
     * active reset token, or the email failed to enqueue must not be observable
     * (anti-enumeration policy).
     */
    public function __invoke(RequestPasswordResetCommand $command): void
    {
        $user = $this->users->findOneByEmail($command->email);

        if (!$user instanceof User) {
            return;
        }

        if ($user->hasActivePasswordResetToken()) {
            return;
        }

        try {
            $this->passwordResetEmailSender->send($user);

            // Inside the try: the sender clears the token and never flushes on
            // a failed enqueue, so a record out here would claim a reset that
            // was never issued. Keyed on the internal id, and no early-return
            // branch records, so nothing answers "does this account exist".
            $this->auditor->record(
                'account.password_reset_requested',
                AuditOutcome::Success,
                ['userId' => (string) $user->id],
                new AuditSubject('user', (string) $user->id),
            );
        } catch (\Throwable) {
        }
    }
}
