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
        } catch (\Throwable) {
        }

        // Keyed on the internal id and written only where a user resolved: the
        // submitted address never enters the record, and no branch that returns
        // early writes one, so nothing here can answer "does this account exist".
        $this->auditor->record(
            'account.password_reset.requested',
            AuditOutcome::Success,
            ['userId' => (string) $user->id],
            new AuditSubject('user', (string) $user->id),
        );
    }
}
