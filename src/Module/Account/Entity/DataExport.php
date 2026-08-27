<?php

declare(strict_types=1);

namespace App\Module\Account\Entity;

use App\Module\Account\Repository\DataExportRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DataExportRepository::class)]
#[ORM\Table(name: 'data_exports')]
// The application-level pending-check in RequestDataExportHandler is a
// TOCTOU race under concurrent requests, so the DB enforces the real
// invariant: at most one pending export per user.
#[ORM\UniqueConstraint(name: 'uniq_data_exports_pending_user', columns: ['user_id'], options: ['where' => "((status)::text = 'pending'::text)"])]
class DataExport
{
    private const int LINK_VALIDITY_HOURS = 48;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(length: 20, enumType: DataExportStatus::class)]
    public DataExportStatus $status = DataExportStatus::Pending;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $downloadTokenHash = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $expiresAt = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public readonly User $user,

        #[ORM\Column]
        public readonly \DateTimeImmutable $requestedAt = new \DateTimeImmutable(),
    ) {
    }

    /** Marks the export ready and returns the single-use raw download token. */
    public function complete(): string
    {
        $raw = bin2hex(random_bytes(32));
        $this->downloadTokenHash = hash('sha256', $raw);
        $this->status = DataExportStatus::Ready;
        $this->completedAt = new \DateTimeImmutable();
        $this->expiresAt = $this->completedAt->modify(sprintf('+%d hours', self::LINK_VALIDITY_HOURS));

        return $raw;
    }

    public function fail(): void
    {
        $this->status = DataExportStatus::Failed;
        $this->completedAt = new \DateTimeImmutable();
    }

    /**
     * Whether the archive may still be handed over at all — status and window,
     * with no opinion on who is asking. The caller supplies the identity: the
     * signed-in owner, or a matching token from the emailed link.
     */
    public function isDownloadable(): bool
    {
        // A Ready export must carry an expiry; a null expiry is an invalid
        // state and must never grant indefinite access.
        return DataExportStatus::Ready === $this->status
            && null !== $this->expiresAt
            && !$this->isExpired();
    }

    public function isDownloadTokenValid(string $token): bool
    {
        if (!$this->isDownloadable() || null === $this->downloadTokenHash) {
            return false;
        }

        return hash_equals($this->downloadTokenHash, hash('sha256', $token));
    }

    public function isExpired(): bool
    {
        // <= : the boundary instant itself is already expired (fail closed).
        // No injected clock — a 48h window doesn't warrant one; the boundary
        // is covered by the past-expiry test.
        return null !== $this->expiresAt && $this->expiresAt <= new \DateTimeImmutable();
    }

    /**
     * Single source of truth for an export archive's key in the export
     * storage. Where that storage physically is — a local directory or an
     * S3 bucket — is the storage's business, not the entity's.
     */
    public static function computeArchiveKey(Uuid $id): string
    {
        return sprintf('%s.zip', (string) $id);
    }
}
