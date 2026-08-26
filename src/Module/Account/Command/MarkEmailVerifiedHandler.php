<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;

/**
 * Marks an account's email verified without the emailed link. Unlike
 * VerifyEmailHandler, which consumes a token a visitor supplied, this is the
 * operator-side escape hatch for an instance whose outbound mail is broken —
 * an unverified administrator is parked on the check-email page and cannot
 * reach the admin area at all.
 */
#[WithMonologChannel('app_security')]
final readonly class MarkEmailVerifiedHandler
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
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
                $this->logger->info('account.user.verification_token_revoked_by_operator', ['userId' => (string) $user->id]);
            }

            return new MarkEmailVerifiedResult(verified: false, tokenRevoked: $tokenRevoked);
        }

        $user->emailVerifiedAt = new \DateTimeImmutable();
        $this->em->flush();

        $this->logger->info('account.user.email_verified_by_operator', ['userId' => (string) $user->id]);

        return new MarkEmailVerifiedResult(verified: true, tokenRevoked: $tokenRevoked);
    }
}
