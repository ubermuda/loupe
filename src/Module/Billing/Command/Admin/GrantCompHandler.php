<?php

declare(strict_types=1);

namespace App\Module\Billing\Command\Admin;

use App\Exception\DomainErrors;
use App\Module\Billing\Entity\Subscription;
use App\Module\Billing\Entity\SubscriptionKind;
use App\Module\Billing\Service\TrialProvisioner;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class GrantCompHandler
{
    public function __construct(
        private TrialProvisioner $trialProvisioner,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
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
            $this->logger->info('billing.account.reenabled', ['userId' => (string) $command->target->id]);
        }

        $this->logger->info('billing.comp.granted', [
            'targetId' => (string) $command->target->id,
            'actorId' => (string) $command->actor->id,
        ]);

        return $comp;
    }
}
