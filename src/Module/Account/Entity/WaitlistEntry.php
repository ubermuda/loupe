<?php

declare(strict_types=1);

namespace App\Module\Account\Entity;

use App\Module\Account\Repository\WaitlistEntryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: WaitlistEntryRepository::class)]
#[ORM\Table(name: 'waitlist_entries')]
class WaitlistEntry
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $invitedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $inviteExpiresAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $convertedAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $inviteTokenHash = null;

    public function __construct(
        /** @phpstan-var non-empty-string */
        #[ORM\Column(length: 180, unique: true)]
        public string $email {
            set(string $email) {
                if ('' === $email) {
                    throw new \InvalidArgumentException('Email must not be empty.');
                }
                $this->email = strtolower($email);
            }
        },

        #[ORM\Column]
        public \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }

    public function issueInviteToken(?\DateTimeImmutable $expiresAt = null): string
    {
        $token = bin2hex(random_bytes(32));
        $this->inviteTokenHash = hash('sha256', $token);
        $this->invitedAt = new \DateTimeImmutable();
        $this->inviteExpiresAt = $expiresAt ?? new \DateTimeImmutable('+7 days');

        return $token;
    }

    public function isInviteTokenValid(string $token): bool
    {
        $hash = $this->inviteTokenHash;
        $expiresAt = $this->inviteExpiresAt;

        if (null === $hash || null === $expiresAt || null !== $this->convertedAt) {
            return false;
        }

        if ($expiresAt < new \DateTimeImmutable()) {
            return false;
        }

        return hash_equals($hash, hash('sha256', $token));
    }

    public function isInvited(): bool
    {
        return null !== $this->invitedAt;
    }

    /**
     * True when this entry should be offered for (re-)invitation: it has
     * never been invited, or its previous invite link expired unused. False
     * once converted (already registered) or while a still-valid invite is
     * outstanding — an active invite must not be silently replaced.
     */
    public function needsInvite(): bool
    {
        if (null !== $this->convertedAt) {
            return false;
        }

        if (null === $this->invitedAt) {
            return true;
        }

        return null === $this->inviteExpiresAt || $this->inviteExpiresAt < new \DateTimeImmutable();
    }

    public function markConverted(): void
    {
        $this->convertedAt = new \DateTimeImmutable();
    }
}
