<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One page element a comment points at. A comment carries several, so it can
 * say something about the relationship between them. A row keeps its own
 * `selector` and `text`; `url` stays on the comment, because every anchor of
 * one comment is on one page.
 *
 * The quote fields are the W3C TextQuoteSelector shape, for an anchor on a run
 * of text rather than a whole element. Nothing writes them yet.
 */
#[ORM\Entity]
#[ORM\Table(name: 'site_review_comment_anchors')]
class SiteReviewCommentAnchor
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: SiteReviewComment::class, inversedBy: 'anchors')]
        public readonly SiteReviewComment $comment,

        #[ORM\Column]
        public readonly int $position,

        #[ORM\Column(type: Types::TEXT)]
        public readonly string $selector,

        #[ORM\Column(type: Types::TEXT)]
        public readonly string $text,

        #[ORM\Column(type: Types::TEXT, nullable: true)]
        public readonly ?string $quote = null,

        #[ORM\Column(type: Types::TEXT, nullable: true)]
        public readonly ?string $quotePrefix = null,

        #[ORM\Column(type: Types::TEXT, nullable: true)]
        public readonly ?string $quoteSuffix = null,
    ) {
    }
}
