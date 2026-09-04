<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Entity;

use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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
    public SiteReviewCommentStatus $status = SiteReviewCommentStatus::Pending;

    /**
     * Freehand drawing over the page. Nothing writes it yet.
     *
     * @var list<mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $strokes = null;

    /** @var Collection<int, SiteReviewCommentAnchor> */
    #[ORM\OneToMany(targetEntity: SiteReviewCommentAnchor::class, mappedBy: 'comment', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    public Collection $anchors;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\Column]
        public readonly int $position,

        // Mutable: the widget can edit the body while the comment is still Pending.
        #[ORM\Column(type: Types::TEXT)]
        public string $body,

        #[ORM\Column(type: Types::TEXT)]
        public readonly string $url,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        $this->anchors = new ArrayCollection();
    }

    /**
     * Append an anchor, numbered after the ones already held. Anchors are
     * ordered, so a caller must never pick the position itself.
     */
    public function addAnchor(
        string $selector,
        string $text,
        ?string $quote = null,
        ?string $quotePrefix = null,
        ?string $quoteSuffix = null,
    ): self {
        $this->anchors->add(new SiteReviewCommentAnchor(
            comment: $this,
            position: $this->anchors->count(),
            selector: $selector,
            text: $text,
            quote: $quote,
            quotePrefix: $quotePrefix,
            quoteSuffix: $quoteSuffix,
        ));

        return $this;
    }
}
