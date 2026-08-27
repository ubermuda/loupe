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
class Review
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        // One verdict per version, enforced by the UNIQUE index OneToOne implies.
        // submitted_at is TIMESTAMP(0), so two rows on one version submitted in the
        // same second are indistinguishable in time — "the latest verdict" would
        // then be whichever the database happened to return, and undoing it could
        // remove the wrong one. The invariant removes the question.
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\OneToOne(targetEntity: DocumentVersion::class)]
        public readonly DocumentVersion $version,

        #[ORM\Column(enumType: Verdict::class)]
        public readonly Verdict $verdict,

        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public readonly User $reviewer,

        #[ORM\Column]
        public readonly \DateTimeImmutable $submittedAt = new \DateTimeImmutable(),
    ) {
    }
}
