<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Entity;

use App\Module\Account\Entity\User;
use App\Module\SiteReview\Repository\SiteReviewBatchRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SiteReviewBatchRepository::class)]
#[ORM\Table(name: 'site_review_batches')]
class SiteReviewBatch
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    /** @var Collection<int, SiteReviewComment> */
    #[ORM\OneToMany(targetEntity: SiteReviewComment::class, mappedBy: 'batch', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    public Collection $comments;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public readonly User $owner,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        $this->comments = new ArrayCollection();
    }

    public function addComment(string $body, string $selector, string $text, string $url): SiteReviewComment
    {
        $comment = new SiteReviewComment($this, $this->comments->count(), $body, $selector, $text, $url);
        $this->comments->add($comment);

        return $comment;
    }
}
