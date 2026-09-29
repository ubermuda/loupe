<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Project\Entity\Project;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Ties a wait item to the card it waits on. The card id has no foreign key,
 * so the row outlives a card delete and Loupe can still close the item.
 */
#[ORM\Entity(repositoryClass: InboxCardWatchRepository::class)]
#[ORM\Table(name: 'inbox_card_watches')]
// One open watch per card. The predicate is written the way Postgres stores it.
#[ORM\UniqueConstraint(name: 'uniq_inbox_card_watches_open_card', columns: ['card_id'], options: ['where' => '(closed_at IS NULL)'])]
class InboxCardWatch
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Project::class)]
    public readonly Project $project;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $dismissedAt = null;

    /** @var Collection<int, InboxCardWait> */
    #[ORM\OneToMany(targetEntity: InboxCardWait::class, mappedBy: 'watch', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['startedAt' => 'ASC'])]
    public Collection $waits;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\OneToOne(targetEntity: InboxItem::class)]
        public readonly InboxItem $item,

        #[ORM\Column(name: 'card_id', type: UuidType::NAME)]
        public readonly Uuid $cardId,

        #[ORM\Column]
        public readonly int $cardNumber,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        if (InboxItemKind::Wait !== $item->kind) {
            throw new \InvalidArgumentException('A card watch belongs to a wait inbox item.');
        }

        $this->project = $item->project;
        $this->waits = new ArrayCollection();
    }
}
