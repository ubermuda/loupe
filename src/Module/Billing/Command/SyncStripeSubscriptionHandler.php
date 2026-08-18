<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Entity\BillingStatus;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Billing\Service\StripeGatewayInterface;
use App\Module\Billing\Service\SubscriptionView;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class SyncStripeSubscriptionHandler
{
    public function __construct(
        private BillingProfileRepository $billingProfiles,
        private StripeGatewayInterface $stripe,
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

        // Asked before the lock is taken, deliberately: this is a network call,
        // and holding a row lock for its duration would block the very event it
        // is being compared against. The read may be stale, in which case a tie
        // goes unresolved below and arrival order decides as it did before.
        $observedEventId = $profile->lastStripeEventId;
        $authoritative = $this->sameSecondAs($command, $profile)
            ? $this->stripe->retrieveSubscription($command->stripeSubscriptionId)
            : null;

        // Stripe can deliver two events at once, and both requests would
        // otherwise read the same profile, pass their ordering checks and race
        // to flush — the loser's older snapshot winning. The whole
        // check-then-write runs under a write lock on the row instead.
        $this->em->wrapInTransaction(function () use ($command, $profile, $authoritative, $observedEventId): void {
            $this->em->lock($profile, LockMode::PESSIMISTIC_WRITE);
            // lock() takes the row but does not refresh what is in memory; the
            // ordering checks must see what a racing request committed.
            $this->em->refresh($profile);

            // A racing request that committed after the lookup knows something
            // the lookup could not, so its answer is dropped rather than allowed
            // to overwrite newer state with an older reading.
            $this->apply($command, $profile, $profile->lastStripeEventId === $observedEventId ? $authoritative : null);
        });
    }

    /** A distinct event Stripe stamped in the same second as the last one applied. */
    private function sameSecondAs(SyncStripeSubscriptionCommand $command, BillingProfile $profile): bool
    {
        return null !== $profile->lastStripeEventAt
            && $command->eventCreatedAt->getTimestamp() === $profile->lastStripeEventAt->getTimestamp()
            && $command->stripeEventId !== $profile->lastStripeEventId;
    }

    /**
     * Stripe guarantees neither ordering nor exactly-once delivery, so two
     * things must be filtered out here, and only those two — then a same-second
     * pair, which no timestamp can order, is settled by what Stripe holds now.
     */
    private function apply(SyncStripeSubscriptionCommand $command, BillingProfile $profile, ?SubscriptionView $authoritative): void
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

        // A deletion is terminal until something strictly newer supersedes it,
        // so nothing from its own second may reopen it. Ordinary same-second
        // events are not dropped here — a subscription is routinely created
        // `incomplete` and updated to `active` within one second, and both
        // belong; which of them wins is decided further down.
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

        // Within one second `created` cannot order two events, so whichever
        // arrived last would otherwise win — and a `created` (incomplete)
        // landing after its own `updated` (active) paywalls someone who has
        // just paid. Where Stripe answered, its state settles it instead.
        $stripeStatus = $command->stripeStatus;
        $currentPeriodEnd = $command->currentPeriodEnd;
        if (null !== $authoritative && $this->sameSecondAs($command, $profile)) {
            $stripeStatus = $authoritative->status;
            $currentPeriodEnd = $authoritative->currentPeriodEnd;
            $this->logger->info('billing.webhook.tie_broken_by_lookup', [
                'stripeCustomerId' => $command->stripeCustomerId,
                'eventId' => $command->stripeEventId,
                'eventStatus' => $command->stripeStatus,
                'stripeStatus' => $authoritative->status,
            ]);
        }

        $profile->stripeSubscriptionId = $command->stripeSubscriptionId;
        $profile->status = BillingStatus::fromStripeStatus($stripeStatus);
        $profile->currentPeriodEnd = $currentPeriodEnd;
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

            $this->waitlistEntries->findOneByEmail($user->email)?->markConverted();
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
