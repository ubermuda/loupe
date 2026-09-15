<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

use App\Doctrine\SearchLanguage;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Project\Entity\Project;
use App\Security\ProjectScopedSubject;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use MartinGeorgiev\Doctrine\DBAL\Type as PostgresType;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** One question or one to-do that an agent hands to the project owner. */
#[ORM\Entity(repositoryClass: InboxItemRepository::class)]
// No access method: DBAL's Postgres platform ignores index flags, and the
// migration creates it USING gin. flags: ['gin'] would make the comparator emit
// a DROP plus a plain CREATE INDEX, downgrading it to a B-tree that @@ never uses.
#[ORM\Index(name: 'idx_inbox_items_search_vector', columns: ['search_vector'])]
#[ORM\Index(name: 'idx_inbox_items_project_search_language', columns: ['project_id', 'search_language'])]
#[ORM\Table(name: 'inbox_items')]
#[ORM\UniqueConstraint(name: 'uniq_inbox_item_project_number', columns: ['project_id', 'number'])]
class InboxItem implements ProjectScopedSubject
{
    public const int MAX_TITLE_LENGTH = 255;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(length: 20, enumType: InboxItemState::class)]
    public InboxItemState $state = InboxItemState::Open;

    /** @var list<int> indexes into $options */
    #[ORM\Column(type: Types::JSON)]
    public array $selectedOptions = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $answerText = null;

    /** A withdraw reason or a decline note. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $closeNote = null;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $closedAt = null;

    /**
     * Only Postgres can build a tsvector, so the ORM never writes this column.
     * The mapping exists so DQL can name it.
     */
    #[ORM\Column(name: 'search_vector', type: PostgresType::TSVECTOR, nullable: true, insertable: false, updatable: false)]
    public ?string $searchVector = null;

    /** @var Collection<int, InboxItemCard> */
    #[ORM\OneToMany(targetEntity: InboxItemCard::class, mappedBy: 'item', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['linkedAt' => 'ASC'])]
    public Collection $cards;

    /** @var Collection<int, InboxItemDocument> */
    #[ORM\OneToMany(targetEntity: InboxItemDocument::class, mappedBy: 'item', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['linkedAt' => 'ASC'])]
    public Collection $documents;

    /**
     * @param list<string> $options empty for a to-do
     */
    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        /** The short number a person says out loud, counting from 1 inside the project. */
        #[ORM\Column]
        public readonly int $number,

        #[ORM\Column(length: 20, enumType: InboxItemKind::class)]
        public readonly InboxItemKind $kind,

        #[ORM\Column(length: self::MAX_TITLE_LENGTH)]
        public string $title,

        #[ORM\Column]
        public bool $blocking,

        #[ORM\Column(type: Types::TEXT, nullable: true)]
        public ?string $body = null,

        #[ORM\Column(type: Types::JSON)]
        public array $options = [],

        #[ORM\Column]
        public bool $multiple = false,

        #[ORM\Column]
        public bool $freeText = false,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),

        /** The configuration $searchVector is built with, and the one a query is parsed in for this row. */
        #[ORM\Column(name: 'search_language', length: 20, enumType: SearchLanguage::class, options: ['default' => SearchLanguage::DEFAULT->value])]
        public readonly SearchLanguage $searchLanguage = SearchLanguage::DEFAULT,
    ) {
        $this->cards = new ArrayCollection();
        $this->documents = new ArrayCollection();
        $this->updatedAt = $this->createdAt;
    }

    #[\Override]
    public function scopedProject(): Project
    {
        return $this->project;
    }

    #[\Override]
    public function scopedSubjectType(): string
    {
        return 'inbox_item';
    }
}
