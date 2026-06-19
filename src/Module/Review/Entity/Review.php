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
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: DocumentVersion::class)]
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
