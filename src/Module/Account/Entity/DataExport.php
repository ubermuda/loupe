<?php

declare(strict_types=1);

namespace App\Module\Account\Entity;

use App\Module\Account\Repository\DataExportRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DataExportRepository::class)]
#[ORM\Table(name: 'data_exports')]
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

    public function isDownloadTokenValid(string $token): bool
    {
        if (DataExportStatus::Ready !== $this->status || null === $this->downloadTokenHash) {
            return false;
        }

        // A Ready export must carry an expiry; a null expiry is an invalid
        // state and must never grant indefinite access.
        if (null === $this->expiresAt || $this->isExpired()) {
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

    /** Single source of truth for where an export archive lives on disk. */
    public static function computeArchivePath(string $projectDir, Uuid $id): string
    {
        return sprintf('%s/var/exports/%s.zip', $projectDir, (string) $id);
    }
}
