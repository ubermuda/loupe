<?php

declare(strict_types=1);

namespace App\Module\Billing\Entity;

use App\Module\Account\Entity\User;
use App\Module\Billing\Repository\BetaInviteRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A single-use link that lets one person past a full registration cap and
 * grants a comp. The user who redeemed it is the beta mark.
 */
#[ORM\Entity(repositoryClass: BetaInviteRepository::class)]
#[ORM\Table(name: 'beta_invites')]
class BetaInvite
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[ORM\ManyToOne(targetEntity: User::class)]
    public ?User $redeemedBy = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $redeemedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $revokedAt = null;

    private function __construct(
        /** Only the hash is stored, so a database leak yields no usable link. */
        #[ORM\Column(length: 64, unique: true)]
        public string $tokenHash,

        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public ?User $createdBy,

        #[ORM\Column(length: 255, nullable: true)]
        public ?string $note,

        #[ORM\Column]
        public \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }

    /**
     * @return array{self, string} the invite and the raw token, which exists only here
     */
    public static function issue(?User $createdBy, ?string $note = null): array
    {
        $token = bin2hex(random_bytes(32));

        return [new self(self::hashToken($token), $createdBy, $note), $token];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function matches(string $token): bool
    {
        return hash_equals($this->tokenHash, self::hashToken($token));
    }

    public function isRedeemed(): bool
    {
        return null !== $this->redeemedAt;
    }

    public function isRedeemedBy(User $user): bool
    {
        return null !== $user->id && true === $this->redeemedBy?->id?->equals($user->id);
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function isUsable(): bool
    {
        return !$this->isRedeemed() && !$this->isRevoked();
    }

    /** @throws \LogicException when the invite is already redeemed or revoked */
    public function redeem(User $user): void
    {
        if (!$this->isUsable()) {
            throw new \LogicException('This beta invite is no longer usable.');
        }

        $this->redeemedBy = $user;
        $this->redeemedAt = new \DateTimeImmutable();
    }

    /** @throws \LogicException when the invite is already redeemed */
    public function revoke(): void
    {
        if ($this->isRedeemed()) {
            throw new \LogicException('A redeemed beta invite cannot be revoked.');
        }

        $this->revokedAt ??= new \DateTimeImmutable();
    }
}
