<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Entity;

use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SiteReviewCommentRepository::class)]
#[ORM\Table(name: 'site_review_comments')]
class SiteReviewComment
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(length: 20, enumType: SiteReviewCommentStatus::class)]
    public SiteReviewCommentStatus $status = SiteReviewCommentStatus::Draft;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\Column]
        public readonly int $position,

        // Mutable: the widget can edit the body while the comment is still a Draft.
        #[ORM\Column(type: Types::TEXT)]
        public string $body,

        #[ORM\Column(type: Types::TEXT)]
        public readonly string $selector,

        #[ORM\Column(type: Types::TEXT)]
        public readonly string $text,

        #[ORM\Column(type: Types::TEXT)]
        public readonly string $url,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }
}
