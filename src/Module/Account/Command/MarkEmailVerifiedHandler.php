<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

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
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return bool true when the account was newly verified, false when it already was
     *
     * @throws DomainErrors when no account matches the email
     */
    public function __invoke(MarkEmailVerifiedCommand $command): bool
    {
        $user = $this->users->findOneByEmail($command->email)
            ?? throw new DomainErrors(['email' => 'account.console.error.user_not_found']);

        if ($user->isVerified()) {
            return false;
        }

        // Clear the outstanding token too, mirroring VerifyEmailHandler: a
        // pending link that still resolves after the account is verified is a
        // live credential nobody needs.
        $user->clearEmailVerificationToken();
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $this->em->flush();

        $this->logger->info('account.user.email_verified_by_operator', ['userId' => (string) $user->id]);

        return true;
    }
}
