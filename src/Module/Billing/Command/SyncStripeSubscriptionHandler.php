<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

use App\Module\Billing\Entity\BillingStatus;
use App\Module\Billing\Repository\BillingProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class SyncStripeSubscriptionHandler
{
    public const string SUBSCRIPTION_DELETED = 'customer.subscription.deleted';

    public function __construct(
        private BillingProfileRepository $billingProfiles,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncStripeSubscriptionCommand $command): void
    {
        $profile = $this->billingProfiles->findOneByStripeCustomerId($command->stripeCustomerId);
        if (null === $profile) {
            // Someone else's Stripe account, or a customer created outside this
            // app. Nothing to write — the caller still acknowledges so Stripe
            // stops retrying.
            $this->logger->warning('billing.webhook.unknown_customer', ['stripeCustomerId' => $command->stripeCustomerId]);

            return;
        }

        // Stripe guarantees neither ordering nor exactly-once delivery, so two
        // things must be filtered out, and only those two.
        //
        // A strictly older event is a stale snapshot (an `updated` arriving
        // after `deleted`) and must not overwrite newer state.
        if (null !== $profile->lastStripeEventAt && $command->eventCreatedAt < $profile->lastStripeEventAt) {
            $this->logger->info('billing.webhook.stale_event', [
                'stripeCustomerId' => $command->stripeCustomerId,
                'eventId' => $command->stripeEventId,
                'eventCreatedAt' => $command->eventCreatedAt->format(\DATE_ATOM),
                'lastStripeEventAt' => $profile->lastStripeEventAt->format(\DATE_ATOM),
            ]);

            return;
        }

        // Within one second `created` cannot order two events at all, so arrival
        // order decides — except behind a deletion. A deletion is terminal until
        // something strictly newer supersedes it, so an `updated` that shares its
        // second never hands access back. Ordinary same-second events still both
        // apply: a subscription is routinely created as `incomplete` and updated
        // to `active` inside the same second, and dropping the second one would
        // paywall a paying customer.
        if (self::SUBSCRIPTION_DELETED === $profile->lastStripeEventType
            && null !== $profile->lastStripeEventAt
            && $command->eventCreatedAt <= $profile->lastStripeEventAt) {
            $this->logger->info('billing.webhook.stale_event', [
                'stripeCustomerId' => $command->stripeCustomerId,
                'eventId' => $command->stripeEventId,
                'reason' => 'not newer than the deletion already applied',
            ]);

            return;
        }

        // The same event delivered twice is a replay. Timestamps cannot detect
        // it — Stripe's `created` has one-second resolution, and a `created`
        // event followed immediately by an `updated` one legitimately shares a
        // second — so the event id is what distinguishes them. Equal timestamps
        // with a different id are applied in arrival order.
        if (null !== $profile->lastStripeEventId && $command->stripeEventId === $profile->lastStripeEventId) {
            $this->logger->info('billing.webhook.replayed_event', [
                'stripeCustomerId' => $command->stripeCustomerId,
                'eventId' => $command->stripeEventId,
            ]);

            return;
        }

        $profile->stripeSubscriptionId = $command->stripeSubscriptionId;
        $profile->status = BillingStatus::fromStripeStatus($command->stripeStatus);
        $profile->currentPeriodEnd = $command->currentPeriodEnd;
        $profile->lastStripeEventAt = $command->eventCreatedAt;
        $profile->lastStripeEventId = $command->stripeEventId;
        $profile->lastStripeEventType = $command->stripeEventType;
        $this->em->flush();

        $this->logger->info('billing.subscription.synced', [
            'stripeCustomerId' => $command->stripeCustomerId,
            'stripeSubscriptionId' => $command->stripeSubscriptionId,
            'status' => $profile->status->value,
        ]);
    }
}
