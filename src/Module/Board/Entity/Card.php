<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CardRepository::class)]
// Doctrine indexes the project join column and nothing else. The board's only
// read query filters on project and status, then sorts by priority and position.
#[ORM\Index(name: 'idx_board_cards_board_order', columns: ['project_id', 'status', 'priority', 'position'])]
#[ORM\Table(name: 'board_cards')]
class Card
{
    /** Mirrors the title column's length so callers can reject an over-long title before Postgres does. */
    public const int MAX_TITLE_LENGTH = 255;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    /**
     * Set when the card enters Done and cleared when it leaves. The Done column
     * sorts on this rather than on $position, which it does not maintain.
     */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    /** @var Collection<int, CardPullRequest> */
    #[ORM\OneToMany(targetEntity: CardPullRequest::class, mappedBy: 'card', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['addedAt' => 'ASC'])]
    public Collection $pullRequests;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\Column(length: self::MAX_TITLE_LENGTH)]
        public string $title,

        #[ORM\Column(type: Types::TEXT)]
        public string $body,

        #[ORM\Column(length: 20, enumType: CardType::class)]
        public CardType $type = CardType::Feature,

        #[ORM\Column(type: Types::INTEGER, enumType: CardPriority::class)]
        public CardPriority $priority = CardPriority::Medium,

        #[ORM\Column(length: 20, enumType: CardStatus::class)]
        public CardStatus $status = CardStatus::Backlog,

        #[ORM\Column(length: 20, enumType: CardOrigin::class)]
        public readonly CardOrigin $origin = CardOrigin::Agent,

        /** Rank inside the card's (project, status, priority) group, counting from 0. Done ignores it. */
        #[ORM\Column]
        public int $position = 0,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        $this->pullRequests = new ArrayCollection();
        $this->updatedAt = $this->createdAt;
    }

    /** Replaces every pull request link with the given set. An empty list clears them. */
    public function replacePullRequests(CardPullRequest ...$links): void
    {
        $this->pullRequests->clear();
        foreach ($links as $link) {
            $this->pullRequests->add($link);
        }
    }
}
