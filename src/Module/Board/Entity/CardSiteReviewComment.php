<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\SiteReview\Entity\SiteReviewComment;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A site-review comment attached to the card its page said it was made against.
 *
 * The row lives in Board because Board is what understands the marker. The
 * comment carries an opaque `context` string that SiteReview never parses, so
 * nothing in that module knows a card exists.
 *
 * A comment attaches to at most one card, which the unique column states. The
 * comment side deletes on cascade, so removing a comment removes its link
 * without SiteReview knowing it had one.
 */
#[ORM\Entity(repositoryClass: CardSiteReviewCommentRepository::class)]
#[ORM\Table(name: 'board_card_site_review_comments')]
class CardSiteReviewComment
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Card::class)]
        public readonly Card $card,

        #[ORM\JoinColumn(unique: true, nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: SiteReviewComment::class)]
        public readonly SiteReviewComment $comment,

        #[ORM\Column]
        public readonly \DateTimeImmutable $linkedAt = new \DateTimeImmutable(),
    ) {
    }
}
