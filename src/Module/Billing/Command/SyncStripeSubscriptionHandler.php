<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

use App\Module\Billing\Entity\BillingStatus;
use App\Module\Billing\Repository\BillingProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class SyncStripeSubscriptionHandler
{
    public function __construct(
        private BillingProfileRepository $profiles,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncStripeSubscriptionCommand $command): void
    {
        $profile = $this->profiles->findOneByStripeCustomerId($command->stripeCustomerId);
        if (null === $profile) {
            // Someone else's Stripe account, or a customer created outside this
            // app. Nothing to write — the caller still acknowledges so Stripe
            // stops retrying.
            $this->logger->warning('billing.webhook.unknown_customer', ['stripeCustomerId' => $command->stripeCustomerId]);

            return;
        }

        // Stripe guarantees neither ordering nor exactly-once delivery, so an
        // event that is not strictly newer than the last one applied is dropped:
        // that covers both a replayed duplicate and a stale snapshot (an older
        // `updated` arriving after `deleted`). Stripe timestamps have one-second
        // resolution, so two genuinely distinct events within the same second
        // collapse to the first — the safe trade on a money path, since the next
        // event carries the full subscription state anyway.
        if (null !== $profile->lastStripeEventAt && $command->eventCreatedAt <= $profile->lastStripeEventAt) {
            $this->logger->info('billing.webhook.stale_event', [
                'stripeCustomerId' => $command->stripeCustomerId,
                'eventCreatedAt' => $command->eventCreatedAt->format(\DATE_ATOM),
                'lastStripeEventAt' => $profile->lastStripeEventAt->format(\DATE_ATOM),
            ]);

            return;
        }

        $profile->stripeSubscriptionId = $command->stripeSubscriptionId;
        $profile->status = BillingStatus::fromStripeStatus($command->stripeStatus);
        $profile->currentPeriodEnd = $command->currentPeriodEnd;
        $profile->lastStripeEventAt = $command->eventCreatedAt;
        $this->em->flush();

        $this->logger->info('billing.subscription.synced', [
            'stripeCustomerId' => $command->stripeCustomerId,
            'stripeSubscriptionId' => $command->stripeSubscriptionId,
            'status' => $profile->status->value,
        ]);
    }
}
