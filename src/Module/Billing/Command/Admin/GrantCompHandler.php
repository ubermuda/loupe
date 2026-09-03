<?php

declare(strict_types=1);

namespace App\Module\Billing\Command\Admin;

use App\Exception\DomainErrors;
use App\Module\Billing\Entity\Subscription;
use App\Module\Billing\Entity\SubscriptionKind;
use App\Module\Billing\Service\TrialProvisioner;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class GrantCompHandler
{
    public function __construct(
        private TrialProvisioner $trialProvisioner,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    /** @throws DomainErrors when the account already holds a current comp */
    public function __invoke(GrantCompCommand $command): Subscription
    {
        // ensureProfile() rather than a bare new BillingProfile: it is the one
        // place a profile and its trial are created, and a second creator would
        // fork that invariant.
        $profile = $this->trialProvisioner->ensureProfile($command->target);

        // A DBAL transaction, not EntityManager::wrapInTransaction(): the
        // already-comped path throws out of the closure, and that would close
        // the shared EntityManager.
        [$comp, $reenabled] = $this->em->getConnection()->transactional(
            /** @return array{Subscription, bool} */
            function () use ($command, $profile): array {
                // The profile row is the lock every billing writer takes, so the
                // check below sees whatever a racing grant committed.
                $this->em->lock($profile, LockMode::PESSIMISTIC_WRITE);
                $this->em->refresh($profile);

                $now = new \DateTimeImmutable();
                if (null !== $profile->currentSubscriptionOfKind(SubscriptionKind::Comp, $now)) {
                    throw new DomainErrors(['comp' => 'billing.admin.comp.error.already_comped']);
                }

                $comp = new Subscription($profile, SubscriptionKind::Comp, $now);
                $comp->grantedBy = $command->actor;
                $this->em->persist($comp);

                // The comp grants access now, so the account must stop counting
                // as disabled. A Stripe subscription clears the same marker.
                $user = $profile->user;
                $reenabled = null !== $user->disabledAt;
                $user->disabledAt = null;

                $this->em->flush();

                return [$comp, $reenabled];
            },
        );

        if ($reenabled) {
            $this->record('billing.account_reenabled', $command);
        }

        $this->record('billing.comp_granted', $command);

        return $comp;
    }

    /**
     * The comped account, never the admin: the admin is the actor, and the
     * Auditor resolves that from the security token by itself.
     */
    private function record(string $operation, GrantCompCommand $command): void
    {
        $this->auditor->record(
            $operation,
            AuditOutcome::Success,
            ['userId' => (string) $command->target->id],
            new AuditSubject('user', (string) $command->target->id),
        );
    }
}
