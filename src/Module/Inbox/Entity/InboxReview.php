<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Inbox\Repository\InboxReviewRepository;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Review;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: InboxReviewRepository::class)]
#[ORM\Table(name: 'inbox_reviews')]
class InboxReview
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(length: 20, enumType: InboxReviewTargetKind::class)]
    public readonly InboxReviewTargetKind $targetKind;

    #[ORM\Column(type: Types::TEXT)]
    public string $targetLabel;

    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[ORM\ManyToOne(targetEntity: Document::class)]
    public ?Document $document = null;

    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[ORM\ManyToOne(targetEntity: CardPullRequest::class)]
    public ?CardPullRequest $pullRequest = null;

    #[ORM\Column(length: 20, nullable: true, enumType: InboxReviewVerdict::class)]
    public ?InboxReviewVerdict $verdict = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $note = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $submittedAt = null;

    #[ORM\JoinColumn(nullable: true)]
    #[ORM\ManyToOne(targetEntity: User::class)]
    public ?User $reviewer = null;

    #[ORM\Column(nullable: true)]
    public ?int $reviewedVersionNumber = null;

    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[ORM\ManyToOne(targetEntity: Review::class)]
    public ?Review $documentReview = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\OneToOne(targetEntity: InboxItem::class)]
        public readonly InboxItem $item,
        Document|CardPullRequest $target,
    ) {
        if (InboxItemKind::Review !== $item->kind) {
            throw new \InvalidArgumentException('A review target belongs to a review inbox item.');
        }

        if ($target instanceof Document) {
            $this->targetKind = InboxReviewTargetKind::Document;
            $this->document = $target;
            $this->targetLabel = $target->title;
        } else {
            $this->targetKind = InboxReviewTargetKind::PullRequest;
            $this->pullRequest = $target;
            $this->targetLabel = $target->url;
        }
    }
}
