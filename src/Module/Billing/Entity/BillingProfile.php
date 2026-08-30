<?php

declare(strict_types=1);

namespace App\Module\Billing\Entity;

use App\Module\Account\Entity\User;
use App\Module\Billing\Repository\BillingProfileRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The customer record. It holds Stripe's identity for this user and the
 * bookkeeping that applies webhooks in order. Every grant of access lives in a
 * Subscription row instead.
 */
#[ORM\Entity(repositoryClass: BillingProfileRepository::class)]
#[ORM\Table(name: 'billing_profiles')]
class BillingProfile
{
    /**
     * Stripe event type that terminates a subscription. The single source of
     * truth for the literal, referenced by SyncStripeSubscriptionHandler and
     * StripeWebhookController, which are the only writers of
     * $lastStripeEventType.
     */
    public const string SUBSCRIPTION_DELETED_EVENT_TYPE = 'customer.subscription.deleted';

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $stripeCustomerId = null;

    /**
     * `created` of the last Stripe event applied. Stripe guarantees neither
     * ordering nor exactly-once delivery, so the webhook handler drops any
     * event older than this — a stale out-of-order snapshot (e.g. an old
     * `updated` arriving after `deleted`) never overwrites newer state.
     */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $lastStripeEventAt = null;

    /**
     * Id of the last Stripe event applied. Stripe timestamps have one-second
     * resolution, so `created` alone cannot tell a replay of one event from a
     * second, genuinely different event in the same second — the id can.
     */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $lastStripeEventId = null;

    /**
     * Type of the last Stripe event applied. A `customer.subscription.deleted`
     * is terminal: nothing sharing its second — or older — may hand access back,
     * while an ordinary `updated` carrying a not-yet-paid status may be followed
     * within the same second by the `updated` that activates the subscription.
     */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $lastStripeEventType = null;

    /** @var Collection<int, Subscription> */
    #[ORM\OneToMany(targetEntity: Subscription::class, mappedBy: 'billingProfile')]
    public Collection $subscriptions;

    // Note: no `readonly` on the constructor-promoted columns below. The billing
    // handlers re-read this row under a pessimistic lock, and EntityManager::refresh()
    // rewrites every mapped field — which PHP forbids on an initialised readonly
    // property. Immutability here is a convention, not a language guarantee.
    public function __construct(
        // OneToOne rather than ManyToOne + a unique constraint: one profile per
        // user is the invariant, and OneToOne already implies the unique FK.
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\OneToOne(targetEntity: User::class)]
        public User $user,

        #[ORM\Column]
        public \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        $this->subscriptions = new ArrayCollection();
    }

    /** Whether any grant of any kind allows the user in right now. */
    public function hasCurrentSubscription(\DateTimeImmutable $now): bool
    {
        return array_any(
            $this->subscriptions->toArray(),
            static fn (Subscription $subscription): bool => $subscription->isCurrent($now),
        );
    }

    public function currentSubscriptionOfKind(SubscriptionKind $kind, \DateTimeImmutable $now): ?Subscription
    {
        return array_find(
            $this->subscriptions->toArray(),
            static fn (Subscription $subscription): bool => $kind === $subscription->kind && $subscription->isCurrent($now),
        );
    }

    /** The most recently created grant of one kind, current or not. */
    public function latestSubscriptionOfKind(SubscriptionKind $kind): ?Subscription
    {
        $matches = array_values(array_filter(
            $this->subscriptions->toArray(),
            static fn (Subscription $subscription): bool => $kind === $subscription->kind,
        ));
        usort($matches, static fn (Subscription $a, Subscription $b): int => $a->createdAt <=> $b->createdAt);

        return array_slice($matches, -1)[0] ?? null;
    }

    /**
     * Stripe still holds a subscription for this customer. `PastDue` counts:
     * an unpaid subscription is still a subscription, so the answer is "manage
     * the one you have", never "start a second one" — a second Checkout would
     * create a parallel subscription and bill the user twice. A comp never
     * changes this answer, because a comp is not a Stripe subscription.
     */
    public function hasLiveSubscription(): bool
    {
        return array_any(
            $this->subscriptions->toArray(),
            static fn (Subscription $subscription): bool => SubscriptionKind::Stripe === $subscription->kind
                && null !== $subscription->stripeSubscriptionId
                && in_array($subscription->stripeStatus, [BillingStatus::Active, BillingStatus::PastDue], true),
        );
    }
}
