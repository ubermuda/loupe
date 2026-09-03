<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Marks an account's email verified without the emailed link. Unlike
 * VerifyEmailHandler, which consumes a token a visitor supplied, this is the
 * operator-side escape hatch for an instance whose outbound mail is broken —
 * an unverified administrator is parked on the check-email page and cannot
 * reach the admin area at all.
 */
final readonly class MarkEmailVerifiedHandler
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    /** @throws DomainErrors when no account matches the email */
    public function __invoke(MarkEmailVerifiedCommand $command): MarkEmailVerifiedResult
    {
        $user = $this->users->findOneByEmail($command->email)
            ?? throw new DomainErrors(['email' => 'account.console.error.user_not_found']);

        // Revoked before the already-verified check, not after it: /register/verify
        // logs in whoever presents a valid token and never asks whether the account
        // is verified already, while social linking sets emailVerifiedAt without
        // clearing it. So "already verified" is precisely the state a live login
        // link survives in, and returning early there would leave it working.
        $tokenRevoked = $user->hasEmailVerificationToken();
        $user->clearEmailVerificationToken();

        if ($user->isVerified()) {
            if ($tokenRevoked) {
                $this->em->flush();
                $this->record('account.user_verification_token_revoked_by_operator', $user);
            }

            return new MarkEmailVerifiedResult(verified: false, tokenRevoked: $tokenRevoked);
        }

        $user->emailVerifiedAt = new \DateTimeImmutable();
        $this->em->flush();

        $this->record('account.user_email_verified_by_operator', $user);

        return new MarkEmailVerifiedResult(verified: true, tokenRevoked: $tokenRevoked);
    }

    private function record(string $operation, User $user): void
    {
        $this->auditor->record(
            $operation,
            AuditOutcome::Success,
            ['userId' => (string) $user->id],
            new AuditSubject('user', (string) $user->id),
            Auditor::CATEGORY_SECURITY,
        );
    }
}
