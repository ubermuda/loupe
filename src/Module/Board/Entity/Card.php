<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

use App\Doctrine\SearchLanguage;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Security\ProjectScopedSubject;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use MartinGeorgiev\Doctrine\DBAL\Type as PostgresType;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CardRepository::class)]
// Doctrine indexes the project join column and nothing else. The board's only
// read query filters on project and status, then sorts by priority and position.
#[ORM\Index(name: 'idx_board_cards_board_order', columns: ['project_id', 'status', 'priority', 'position'])]
// No access method: DBAL's Postgres platform ignores index flags, and the
// migration creates it USING gin. flags: ['gin'] would make the comparator emit
// a DROP plus a plain CREATE INDEX, downgrading it to a B-tree that @@ never uses.
#[ORM\Index(name: 'idx_board_cards_search_vector', columns: ['search_vector'])]
// Read by card_search, which asks a project which languages its cards hold
// before it builds one constant tsquery per language.
#[ORM\Index(name: 'idx_board_cards_project_search_language', columns: ['project_id', 'search_language'])]
#[ORM\Table(name: 'board_cards')]
#[ORM\UniqueConstraint(name: 'uniq_board_card_project_number', columns: ['project_id', 'number'])]
class Card implements ProjectScopedSubject
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

    /**
     * Title and body, stemmed and weighted, as one searchable vector. It sits on
     * the card rather than in a table of its own because card_search already
     * filters this table by project, so the GIN scan and the project predicate
     * stay together.
     *
     * The cost is on reads: the column is now in the SELECT list of every Card
     * query in the app.
     *
     * Only Postgres can build a tsvector, so the ORM never writes this column:
     * CardSearchIndexer maintains it, and the mapping exists so DQL can name it.
     * Null until a card is next written — see the backfill migration.
     */
    #[ORM\Column(name: 'search_vector', type: PostgresType::TSVECTOR, nullable: true, insertable: false, updatable: false)]
    public ?string $searchVector = null;

    /** @var Collection<int, CardPullRequest> */
    #[ORM\OneToMany(targetEntity: CardPullRequest::class, mappedBy: 'card', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['addedAt' => 'ASC'])]
    public Collection $pullRequests;

    /** @var Collection<int, CardDocument> */
    #[ORM\OneToMany(targetEntity: CardDocument::class, mappedBy: 'card', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['linkedAt' => 'ASC'])]
    public Collection $documents;

    /**
     * Null on a row an image without this column wrote, which is why nothing
     * reads it directly. Read $reporter instead.
     */
    #[ORM\Column(name: 'reporter', length: 20, nullable: true, enumType: CardOrigin::class)]
    private ?CardOrigin $storedReporter = null;

    /** Who raised the card, falling back to the column release 2 drops. */
    public CardOrigin $reporter {
        get => $this->storedReporter ?? $this->origin;
    }

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\Column(length: self::MAX_TITLE_LENGTH)]
        public string $title,

        #[ORM\Column(type: Types::TEXT)]
        public string $body,

        /** The short number a person says out loud, counting from 1 inside the project. Never unique across projects. */
        #[ORM\Column]
        public readonly int $number,

        #[ORM\Column(length: 20, enumType: CardType::class)]
        public CardType $type = CardType::Feature,

        #[ORM\Column(type: Types::INTEGER, enumType: CardPriority::class)]
        public CardPriority $priority = CardPriority::Medium,

        #[ORM\Column(length: 20, enumType: CardStatus::class)]
        public CardStatus $status = CardStatus::Backlog,

        /** The column release 2 drops. Every write sets it, so an older image still reads the row. */
        #[ORM\Column(length: 20, enumType: CardOrigin::class)]
        public readonly CardOrigin $origin = CardOrigin::Agent,

        /** Rank inside the card's (project, status, priority) group, counting from 0. Done ignores it. */
        #[ORM\Column]
        public int $position = 0,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),

        /**
         * The configuration $searchVector is built with, and the one a query is
         * parsed in for this row. Both sides read it off the same row: a vector
         * stemmed as French and a query parsed as English never meet. Readonly,
         * so the pair cannot drift once CardSearchIndexer has written it.
         */
        #[ORM\Column(name: 'search_language', length: 20, enumType: SearchLanguage::class, options: ['default' => SearchLanguage::DEFAULT->value])]
        public readonly SearchLanguage $searchLanguage = SearchLanguage::DEFAULT,
    ) {
        $this->storedReporter = $this->origin;
        $this->pullRequests = new ArrayCollection();
        $this->documents = new ArrayCollection();
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

    /**
     * Makes the card's document links match the given set exactly.
     *
     * A diff rather than a clear-and-rebuild, which is what the pull request
     * links do. Doctrine issues inserts before orphan-removal deletes, so
     * rebuilding puts a second row with the same (card, document) pair on the
     * wire before the first is gone, and the unique index refuses it.
     */
    public function syncDocuments(Document ...$documents): void
    {
        $wanted = [];
        foreach ($documents as $document) {
            $wanted[(string) $document->id] = $document;
        }

        foreach ($this->documents as $link) {
            $id = (string) $link->document->id;
            if (isset($wanted[$id])) {
                unset($wanted[$id]);

                continue;
            }
            $this->documents->removeElement($link);
        }

        foreach ($wanted as $document) {
            $this->documents->add(new CardDocument($this, $document));
        }
    }

    #[\Override]
    public function scopedProject(): Project
    {
        return $this->project;
    }

    #[\Override]
    public function scopedSubjectType(): string
    {
        return 'card';
    }
}
