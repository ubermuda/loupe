<?php

declare(strict_types=1);

namespace App\Module\Billing\Entity;

use App\Module\Account\Entity\User;
use App\Module\Billing\Repository\BillingProfileRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: BillingProfileRepository::class)]
#[ORM\Table(name: 'billing_profiles')]
class BillingProfile
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(length: 20, enumType: BillingStatus::class)]
    public BillingStatus $status = BillingStatus::Trialing;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $stripeCustomerId = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $stripeSubscriptionId = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $currentPeriodEnd = null;

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
        public \DateTimeImmutable $trialEndsAt,

        #[ORM\Column]
        public \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }

    /**
     * Stripe still holds a subscription for this customer. `PastDue` counts:
     * an unpaid subscription is still a subscription, so the answer is "manage
     * the one you have", never "start a second one" — a second Checkout would
     * create a parallel subscription and bill the user twice once the overdue
     * invoice settles.
     */
    public function hasLiveSubscription(): bool
    {
        return null !== $this->stripeSubscriptionId
            && in_array($this->status, [BillingStatus::Active, BillingStatus::PastDue], true);
    }

    public function isCurrent(\DateTimeImmutable $now): bool
    {
        if (BillingStatus::Active === $this->status) {
            return true;
        }

        return BillingStatus::Trialing === $this->status && $now < $this->trialEndsAt;
    }
}
