<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Entity\BillingStatus;
use App\Module\Billing\Entity\Subscription;
use App\Module\Billing\Entity\SubscriptionKind;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Billing\Repository\SubscriptionRepository;
use App\Module\Billing\Service\StripeGatewayInterface;
use App\Module\Billing\Service\SubscriptionView;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class SyncStripeSubscriptionHandler
{
    public function __construct(
        private BillingProfileRepository $billingProfiles,
        private SubscriptionRepository $subscriptions,
        private StripeGatewayInterface $stripe,
        private WaitlistEntryRepository $waitlistEntries,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(SyncStripeSubscriptionCommand $command): void
    {
        $profile = $this->billingProfiles->findOneByStripeCustomerId($command->stripeCustomerId);
        if (null === $profile) {
            // Someone else's Stripe account, or a customer created outside this
            // app. Nothing to write — the caller still acknowledges so Stripe
            // stops retrying.
            $this->logger->warning('billing.webhook_unknown_customer', ['stripeCustomerId' => $command->stripeCustomerId]);

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
        [$reenabled, $disabledOnCancel] = $this->em->wrapInTransaction(
            /** @return array{bool, bool} */
            function () use ($command, $profile, $authoritative, $observedEventId): array {
                $this->em->lock($profile, LockMode::PESSIMISTIC_WRITE);
                // lock() takes the row but does not refresh what is in memory; the
                // ordering checks must see what a racing request committed.
                $this->em->refresh($profile);

                // A racing request that committed after the lookup knows something
                // the lookup could not, so its answer is dropped rather than allowed
                // to overwrite newer state with an older reading.
                return $this->apply($command, $profile, $profile->lastStripeEventId === $observedEventId ? $authoritative : null);
            },
        );

        // Recorded after the commit, never inside it. The audit sink drains
        // outside the business transaction on purpose, so a record written in
        // here would outlive a rollback and state a change the database never
        // kept.
        $userId = (string) $profile->user->id;
        if ($reenabled) {
            $this->record('billing.account_reenabled', $userId);
        }
        if ($disabledOnCancel) {
            $this->record('billing.account_disabled_on_cancel', $userId);
        }
    }

    private function record(string $operation, string $userId): void
    {
        $this->auditor->record(
            $operation,
            AuditOutcome::Success,
            ['userId' => $userId],
            new AuditSubject('user', $userId),
        );
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
     *
     * @return array{bool, bool} [the account was re-enabled, the account was disabled by a cancellation]
     */
    private function apply(SyncStripeSubscriptionCommand $command, BillingProfile $profile, ?SubscriptionView $authoritative): array
    {
        // A strictly older event is a stale snapshot (an `updated` arriving
        // after `deleted`) and must not overwrite newer state.
        if (null !== $profile->lastStripeEventAt && $command->eventCreatedAt < $profile->lastStripeEventAt) {
            $this->logger->info('billing.webhook_stale_event', [
                'stripeCustomerId' => $command->stripeCustomerId,
                'eventId' => $command->stripeEventId,
                'eventCreatedAt' => $command->eventCreatedAt->format(\DATE_ATOM),
                'lastStripeEventAt' => $profile->lastStripeEventAt->format(\DATE_ATOM),
            ]);

            return [false, false];
        }

        // A deletion is terminal until something strictly newer supersedes it,
        // so nothing from its own second may reopen it. Ordinary same-second
        // events are not dropped here — a subscription is routinely created
        // `incomplete` and updated to `active` within one second, and both
        // belong; which of them wins is decided further down.
        if (BillingProfile::SUBSCRIPTION_DELETED_EVENT_TYPE === $profile->lastStripeEventType
            && null !== $profile->lastStripeEventAt
            && $command->eventCreatedAt <= $profile->lastStripeEventAt) {
            $this->logger->info('billing.webhook_stale_event', [
                'stripeCustomerId' => $command->stripeCustomerId,
                'eventId' => $command->stripeEventId,
                'reason' => 'not newer than the deletion already applied',
            ]);

            return [false, false];
        }

        // The same event delivered twice is a replay. Timestamps cannot detect
        // it — Stripe's `created` has one-second resolution, and a `created`
        // event followed immediately by an `updated` one legitimately shares a
        // second — so the event id is what distinguishes them. Equal timestamps
        // with a different id are applied in arrival order.
        if (null !== $profile->lastStripeEventId && $command->stripeEventId === $profile->lastStripeEventId) {
            $this->logger->info('billing.webhook_replayed_event', [
                'stripeCustomerId' => $command->stripeCustomerId,
                'eventId' => $command->stripeEventId,
            ]);

            return [false, false];
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
            $this->logger->info('billing.webhook_tie_broken_by_lookup', [
                'stripeCustomerId' => $command->stripeCustomerId,
                'eventId' => $command->stripeEventId,
                'eventStatus' => $command->stripeStatus,
                'stripeStatus' => $authoritative->status,
            ]);
        }

        $now = new \DateTimeImmutable();
        $status = BillingStatus::fromStripeStatus($stripeStatus);

        $subscription = $this->subscriptions->findOneByStripeSubscriptionId($command->stripeSubscriptionId);
        if (null === $subscription) {
            $concurrent = $profile->currentSubscriptionOfKind(SubscriptionKind::Stripe, $now);
            if (null !== $concurrent) {
                // wrapInTransaction still flushes on the way out of this return,
                // with an empty changeset. The controller answers 200, so Stripe
                // stops retrying an event this handler refuses every time.
                $this->logger->error('billing.webhook_concurrent_grant', [
                    'stripeCustomerId' => $command->stripeCustomerId,
                    'stripeSubscriptionId' => $command->stripeSubscriptionId,
                    'eventId' => $command->stripeEventId,
                    'currentStripeSubscriptionId' => $concurrent->stripeSubscriptionId,
                ]);

                return [false, false];
            }

            $subscription = new Subscription($profile, SubscriptionKind::Stripe, $now);
            $subscription->stripeSubscriptionId = $command->stripeSubscriptionId;
            $this->em->persist($subscription);
        }

        $subscription->stripeStatus = $status;
        $subscription->endsAt = $this->endsAt($command, $status, $currentPeriodEnd, $now);

        $profile->lastStripeEventAt = $command->eventCreatedAt;
        $profile->lastStripeEventId = $command->stripeEventId;
        $profile->lastStripeEventType = $command->stripeEventType;

        $user = $profile->user;
        $reenabled = false;
        $disabledOnCancel = false;

        if ($profile->hasLiveSubscription() && null !== $user->disabledAt) {
            // They paid — re-enable unconditionally, even if the cap has
            // filled meanwhile (slight over-cap is the accepted trade-off).
            // The survey marker resets so a later cancellation of THIS
            // subscription can be surveyed in its own right.
            $user->disabledAt = null;
            $subscription->surveySentAt = null;
            $reenabled = true;

            $this->waitlistEntries->findOneByEmail($user->email)?->markConverted();
        }

        if (BillingStatus::Canceled === $status && null === $user->disabledAt && !$profile->hasCurrentSubscription($now)) {
            // The subscription is gone and nothing else grants access. A trial
            // or a comp that still runs keeps the account enabled, and a
            // mid-period cancel keeps its access until the paid period lapses,
            // which the sweep settles. The sweep also owns the cancellation
            // survey; this handler never emails.
            $user->disabledAt = $now;
            $disabledOnCancel = true;
        }

        $this->em->flush();

        $this->logger->info('billing.subscription_synced', [
            'stripeCustomerId' => $command->stripeCustomerId,
            'stripeSubscriptionId' => $command->stripeSubscriptionId,
            'status' => $status->value,
        ]);

        return [$reenabled, $disabledOnCancel];
    }

    /**
     * When this grant stops. `active` with no known period end runs on until
     * Stripe says otherwise. A `canceled` status also covers `incomplete`, which
     * never went live, so only a real deletion honours its paid period.
     */
    private function endsAt(SyncStripeSubscriptionCommand $command, BillingStatus $status, ?\DateTimeImmutable $currentPeriodEnd, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        return match ($status) {
            BillingStatus::Active, BillingStatus::Trialing => $currentPeriodEnd,
            BillingStatus::PastDue => $currentPeriodEnd ?? $now,
            BillingStatus::Canceled => BillingProfile::SUBSCRIPTION_DELETED_EVENT_TYPE === $command->stripeEventType
                ? $currentPeriodEnd ?? $now
                : $now,
        };
    }
}
