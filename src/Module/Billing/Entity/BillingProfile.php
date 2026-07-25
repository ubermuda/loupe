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
     * event not strictly newer than this — replays and stale out-of-order
     * snapshots (e.g. an old `updated` arriving after `deleted`) are ignored.
     */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $lastStripeEventAt = null;

    public function __construct(
        // OneToOne rather than ManyToOne + a unique constraint: one profile per
        // user is the invariant, and OneToOne already implies the unique FK.
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\OneToOne(targetEntity: User::class)]
        public readonly User $user,

        #[ORM\Column]
        public \DateTimeImmutable $trialEndsAt,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }

    public function isCurrent(\DateTimeImmutable $now): bool
    {
        if (BillingStatus::Active === $this->status) {
            return true;
        }

        return BillingStatus::Trialing === $this->status && $now < $this->trialEndsAt;
    }
}
