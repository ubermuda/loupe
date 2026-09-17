<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Entity;

use App\Module\Account\Entity\User;
use App\Module\SiteReview\Repository\SiteReviewReplyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SiteReviewReplyRepository::class)]
#[ORM\Index(columns: ['comment_id', 'created_at', 'id'])]
#[ORM\Table(name: 'site_review_replies')]
#[ORM\UniqueConstraint(columns: ['comment_id', 'submission_id'])]
class SiteReviewReply
{
    public const int MAX_BODY_LENGTH = 2000;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: SiteReviewComment::class)]
        public readonly SiteReviewComment $comment,

        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public readonly User $author,

        #[ORM\Column(type: Types::TEXT)]
        public readonly string $body,

        #[ORM\Column(type: UuidType::NAME)]
        public readonly Uuid $submissionId,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }
}
