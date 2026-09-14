<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

use App\Module\Board\Entity\Card;
use App\Module\Inbox\Repository\InboxAskRepository;
use App\Module\Project\Entity\Project;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** The items one agent session hands to the owner at once. It closes when its blocking items close. */
#[ORM\Entity(repositoryClass: InboxAskRepository::class)]
#[ORM\Index(name: 'idx_inbox_asks_session', columns: ['session_id'])]
#[ORM\Table(name: 'inbox_asks')]
// One open ask per session. The predicate is written the way Postgres stores it,
// or the schema comparator reads it as changed on every migrate-diff.
#[ORM\UniqueConstraint(name: 'uniq_inbox_asks_open_session', columns: ['session_id'], options: ['where' => '(closed_at IS NULL)'])]
class InboxAsk
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    /** Filled when the ask closes, from the worker run of its session. */
    #[ORM\JoinColumn(name: 'card_id', nullable: true, onDelete: 'SET NULL')]
    #[ORM\ManyToOne(targetEntity: Card::class)]
    public ?Card $card = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $closedAt = null;

    /** @var Collection<int, InboxAskItem> */
    #[ORM\OneToMany(targetEntity: InboxAskItem::class, mappedBy: 'ask', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['addedAt' => 'ASC'])]
    public Collection $items;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\Column(name: 'session_id', type: UuidType::NAME)]
        public readonly Uuid $sessionId,

        /** Null when an interactive session asked, which no bridge can resume. A later call of the session may fill it in. */
        #[ORM\Column(name: 'bridge_id', type: UuidType::NAME, nullable: true)]
        public ?Uuid $bridgeId = null,

        #[ORM\Column(type: Types::TEXT, nullable: true)]
        public ?string $context = null,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        $this->items = new ArrayCollection();
    }
}
