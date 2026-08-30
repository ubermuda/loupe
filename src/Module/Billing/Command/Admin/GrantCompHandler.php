<?php

declare(strict_types=1);

namespace App\Module\Billing\Command\Admin;

use App\Exception\DomainErrors;
use App\Module\Billing\Entity\Subscription;
use App\Module\Billing\Entity\SubscriptionKind;
use App\Module\Billing\Service\TrialProvisioner;
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

        $now = new \DateTimeImmutable();
        if (null !== $profile->currentSubscriptionOfKind(SubscriptionKind::Comp, $now)) {
            throw new DomainErrors(['comp' => 'billing.admin.comp.error.already_comped']);
        }

        $comp = new Subscription($profile, SubscriptionKind::Comp, $now);
        $comp->grantedBy = $command->actor;

        $this->em->persist($comp);
        $this->em->flush();

        $this->logger->info('billing.comp.granted', [
            'targetId' => (string) $command->target->id,
            'actorId' => (string) $command->actor->id,
        ]);

        return $comp;
    }
}
