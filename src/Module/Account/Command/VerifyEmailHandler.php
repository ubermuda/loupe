<?php

namespace App\Module\Account\Command;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use Doctrine\ORM\EntityManagerInterface;

final readonly class VerifyEmailHandler
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    /**
     * Returns the verified user, or null when the token is missing, unknown,
     * expired or tampered with — the caller must not learn which.
     */
    public function __invoke(VerifyEmailCommand $command): ?User
    {
        if (null === $command->token || '' === $command->token) {
            return null;
        }

        $user = $this->users->findByEmailVerificationToken($command->token);
        if (!$user instanceof User || !$user->isEmailVerificationTokenValid($command->token)) {
            return null;
        }

        $user->clearEmailVerificationToken();
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $this->em->flush();

        $this->auditor->record(
            'account.user.email_verified',
            AuditOutcome::Success,
            ['userId' => (string) $user->id],
            new AuditSubject('user', (string) $user->id),
            Auditor::CATEGORY_SECURITY,
        );

        return $user;
    }
}
