<?php

declare(strict_types=1);

namespace App\Module\Billing\Command\Admin;

use App\Exception\DomainErrors;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class RevokeBetaInviteHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    /** @throws DomainErrors when the invite is already redeemed or revoked */
    public function __invoke(RevokeBetaInviteCommand $command): void
    {
        $invite = $command->invite;

        // A DBAL transaction, not wrapInTransaction(): a DomainErrors thrown out
        // of the closure would close the shared EntityManager.
        $this->em->getConnection()->transactional(function () use ($invite): void {
            // Redemption locks the same row, so a revoke cannot race a sign-up.
            $this->em->lock($invite, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($invite);

            if ($invite->isRedeemed()) {
                throw new DomainErrors(['invite' => 'billing.admin.beta_invite.error.redeemed']);
            }
            if ($invite->isRevoked()) {
                throw new DomainErrors(['invite' => 'billing.admin.beta_invite.error.revoked']);
            }

            $invite->revoke();
            $this->em->flush();
        });

        $id = (string) $invite->id;
        $this->auditor->record(
            'billing.beta_invite_revoked',
            AuditOutcome::Success,
            ['betaInviteId' => $id],
            new AuditSubject('beta_invite', $id),
        );
    }
}
