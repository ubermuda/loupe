<?php

declare(strict_types=1);

namespace App\Module\Review\Entity;

use App\Module\Account\Entity\User;
use App\Module\Review\Repository\ReviewRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ReviewRepository::class)]
#[ORM\Table(name: 'reviews')]
// The log is append-only, so two rows may never claim the same position in it.
#[ORM\UniqueConstraint(name: 'uniq_reviews_version_sequence', columns: ['version_id', 'sequence'])]
class Review
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: DocumentVersion::class)]
        public readonly DocumentVersion $version,

        #[ORM\Column(enumType: Verdict::class)]
        public readonly Verdict $verdict,

        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public readonly User $reviewer,

        // Position in this version's verdict log, 1-based and gapless. It is what
        // orders the log: submitted_at is TIMESTAMP(0), so a verdict and the
        // withdrawal a moment later carry the same value. Assigned under the
        // document's write lock, which is what keeps two appends from claiming one
        // position; the UNIQUE index above says so out loud if they ever do.
        #[ORM\Column]
        public readonly int $sequence = 1,

        #[ORM\Column]
        public readonly \DateTimeImmutable $submittedAt = new \DateTimeImmutable(),
    ) {
    }
}
