<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'site_review_comments')]
class SiteReviewComment
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: SiteReviewBatch::class, inversedBy: 'comments')]
        public readonly SiteReviewBatch $batch,

        #[ORM\Column]
        public readonly int $position,

        #[ORM\Column(type: Types::TEXT)]
        public readonly string $body,

        #[ORM\Column(type: Types::TEXT)]
        public readonly string $selector,

        #[ORM\Column(type: Types::TEXT)]
        public readonly string $text,

        #[ORM\Column(type: Types::TEXT)]
        public readonly string $url,
    ) {
    }
}
