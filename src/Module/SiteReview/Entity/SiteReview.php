<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Entity;

use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Repository\SiteReviewRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SiteReviewRepository::class)]
#[ORM\Table(name: 'site_review_reviews')]
#[ORM\UniqueConstraint(name: 'uniq_site_review_in_progress', columns: ['project_id'], options: ['where' => "((status)::text = 'in-progress'::text)"])]
class SiteReview
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    /** @var Collection<int, SiteReviewComment> */
    #[ORM\OneToMany(targetEntity: SiteReviewComment::class, mappedBy: 'review', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    public Collection $comments;

    #[ORM\Column(length: 20, enumType: SiteReviewStatus::class)]
    public SiteReviewStatus $status = SiteReviewStatus::InProgress;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $submittedAt = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        $this->comments = new ArrayCollection();
    }

    public function markSubmitted(): void
    {
        $this->status = SiteReviewStatus::Submitted;
        $this->submittedAt = new \DateTimeImmutable();
    }

    public function addComment(string $body, string $selector, string $text, string $url): SiteReviewComment
    {
        // max+1, not count(): after a delete-then-add, count() would reuse an
        // existing position and make the OrderBy tie order nondeterministic.
        $position = -1;
        foreach ($this->comments as $existing) {
            $position = max($position, $existing->position);
        }

        $comment = new SiteReviewComment($this, $position + 1, $body, $selector, $text, $url);
        $this->comments->add($comment);

        return $comment;
    }
}
