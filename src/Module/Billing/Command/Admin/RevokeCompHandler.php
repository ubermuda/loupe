<?php

declare(strict_types=1);

namespace App\Module\Billing\Command\Admin;

use App\Exception\DomainErrors;
use App\Module\Billing\Entity\Subscription;
use App\Module\Billing\Entity\SubscriptionKind;
use App\Module\Billing\Repository\BillingProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class RevokeCompHandler
{
    public function __construct(
        private BillingProfileRepository $billingProfiles,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /** @throws DomainErrors when the account holds no current comp */
    public function __invoke(RevokeCompCommand $command): Subscription
    {
        $now = new \DateTimeImmutable();
        $comp = $this->billingProfiles->findOneByUser($command->target)
            ?->currentSubscriptionOfKind(SubscriptionKind::Comp, $now);

        if (!$comp instanceof Subscription) {
            throw new DomainErrors(['comp' => 'billing.admin.comp.error.not_comped']);
        }

        // An end date rather than a delete, so the trail of who was comped and
        // when survives the revocation.
        $comp->endsAt = $now;
        $this->em->flush();

        $this->logger->info('billing.comp.revoked', [
            'targetId' => (string) $command->target->id,
            'actorId' => (string) $command->actor->id,
        ]);

        return $comp;
    }
}
