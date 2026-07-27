<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Entity\BillingStatus;
use App\Module\Billing\Repository\BillingProfileRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class SyncStripeSubscriptionHandler
{
    public function __construct(
        private BillingProfileRepository $billingProfiles,
        private WaitlistEntryRepository $waitlistEntries,
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

        // Stripe can deliver two events at once, and both requests would
        // otherwise read the same profile, pass their ordering checks and race
        // to flush — the loser's older snapshot winning. The whole
        // check-then-write runs under a write lock on the row instead.
        $this->em->wrapInTransaction(function () use ($command, $profile): void {
            $this->em->lock($profile, LockMode::PESSIMISTIC_WRITE);
            // lock() takes the row but does not refresh what is in memory; the
            // ordering checks must see what a racing request committed.
            $this->em->refresh($profile);

            $this->apply($command, $profile);
        });
    }

    /**
     * Stripe guarantees neither ordering nor exactly-once delivery, so two
     * things must be filtered out here, and only those two.
     */
    private function apply(SyncStripeSubscriptionCommand $command, BillingProfile $profile): void
    {
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
        if (BillingProfile::SUBSCRIPTION_DELETED_EVENT_TYPE === $profile->lastStripeEventType
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

        $user = $profile->user;

        if ($profile->hasLiveSubscription() && null !== $user->disabledAt) {
            // They paid — re-enable unconditionally, even if the cap has
            // filled meanwhile (slight over-cap is the accepted trade-off).
            // The cancel-survey marker resets so a later cancellation of THIS
            // subscription can be surveyed in its own right.
            $user->disabledAt = null;
            $profile->cancelSurveySentAt = null;
            $this->logger->info('billing.account.reenabled', ['userId' => (string) $user->id]);

            $waitlistMatch = $this->waitlistEntries->findOneByEmail($user->email);
            if (null !== $waitlistMatch && null === $waitlistMatch->convertedAt) {
                $waitlistMatch->markConverted();
            }
        }

        if (BillingStatus::Canceled === $profile->status
            && null === $user->disabledAt
            && (null === $profile->currentPeriodEnd || $profile->currentPeriodEnd < new \DateTimeImmutable())) {
            // The paid-for period is over (Stripe fires `deleted` at period end
            // for a cancel-at-period-end; only an immediate mid-period cancel
            // carries a future currentPeriodEnd — that account keeps access
            // until the sweep disables it after the date lapses). The sweep
            // also owns the cancellation survey; this handler never emails.
            $user->disabledAt = new \DateTimeImmutable();
            $this->logger->info('billing.account.disabled_on_cancel', ['userId' => (string) $user->id]);
        }

        $this->em->flush();

        $this->logger->info('billing.subscription.synced', [
            'stripeCustomerId' => $command->stripeCustomerId,
            'stripeSubscriptionId' => $command->stripeSubscriptionId,
            'status' => $profile->status->value,
        ]);
    }
}
