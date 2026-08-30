<?php

declare(strict_types=1);

namespace App\Module\Billing\Entity;

use App\Module\Account\Entity\User;
use App\Module\Billing\Repository\SubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One grant of access to a billing profile. A trial, a Stripe subscription and
 * a comp are the same record with a different kind, so several may run at once
 * and none of them has to choose.
 */
#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
#[ORM\Index(name: 'idx_subscriptions_kind_ends_at', columns: ['kind', 'ends_at'])]
#[ORM\Table(name: 'subscriptions')]
class Subscription
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    /** Kind `stripe` only. */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $stripeSubscriptionId = null;

    /**
     * What Stripe last said about this subscription. Kind `stripe` only, and it
     * never decides access: `endsAt` does.
     */
    #[ORM\Column(length: 20, nullable: true, enumType: BillingStatus::class)]
    public ?BillingStatus $stripeStatus = null;

    /**
     * The admin who granted a comp. Kind `comp` only. `SET NULL` on delete, so
     * removing that admin's account keeps the record of the grant.
     */
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[ORM\ManyToOne(targetEntity: User::class)]
    public ?User $grantedBy = null;

    /**
     * When the survey for this grant's ending was handed to the mailer, or
     * deliberately skipped because no survey URL is configured. One per row,
     * because a grant ends exactly once.
     */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $surveySentAt = null;

    /**
     * @throws \LogicException when the profile already holds a current grant of
     *                         the same kind
     */
    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: BillingProfile::class, inversedBy: 'subscriptions')]
        public BillingProfile $billingProfile,

        #[ORM\Column(length: 20, enumType: SubscriptionKind::class)]
        public SubscriptionKind $kind,

        #[ORM\Column]
        public \DateTimeImmutable $startsAt,

        /** Null means open-ended: the grant never stops on its own. */
        #[ORM\Column(nullable: true)]
        public ?\DateTimeImmutable $endsAt = null,

        #[ORM\Column]
        public \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        // At most one current grant per kind, per profile. Enforced here rather
        // than by a partial unique index, which does not round-trip through
        // DBAL's comparator.
        if (null !== $billingProfile->currentSubscriptionOfKind($kind, $startsAt)) {
            throw new \LogicException(sprintf('This billing profile already holds a current %s subscription.', $kind->value));
        }

        $billingProfile->subscriptions->add($this);
    }

    /** The one access rule: started, and not yet ended. */
    public function isCurrent(\DateTimeImmutable $now): bool
    {
        return $this->startsAt <= $now && (null === $this->endsAt || $now < $this->endsAt);
    }
}
