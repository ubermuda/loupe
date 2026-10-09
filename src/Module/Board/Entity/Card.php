<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

use App\Doctrine\SearchLanguage;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\CardTypes;
use App\Security\ProjectScopedSubject;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use MartinGeorgiev\Doctrine\DBAL\Type as PostgresType;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CardRepository::class)]
// The board reads one column at a time, then sorts by position.
#[ORM\Index(name: 'idx_board_cards_column_position', columns: ['column_id', 'position'])]
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
     * Set when the card enters a terminal column and cleared when it leaves for
     * a column that is not terminal. A terminal column sorts on this rather
     * than on $position, which it does not maintain.
     */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    /**
     * The epic this card belongs to. CardParentPolicy keeps it an epic of the
     * same project. The key has no ON DELETE action, so the project delete
     * removes a parent and its children in one statement.
     */
    #[ORM\JoinColumn(name: 'parent_card_id', nullable: true)]
    #[ORM\ManyToOne(targetEntity: self::class)]
    public ?Card $parent = null;

    /** Whether the board draws this epic as a lane. Only an epic reads it. */
    #[ORM\Column(name: 'lane_enabled', options: ['default' => true])]
    public bool $laneEnabled = true;

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
    #[ORM\Column(name: 'reporter', length: 20, nullable: true, enumType: Actor::class)]
    private ?Actor $storedReporter = null;

    /** Who raised the card, falling back to the column release 2 drops. */
    public Actor $reporter {
        get => $this->storedReporter ?? $this->origin;
    }

    /**
     * Null on a row an image without this column wrote, which is why nothing
     * reads it directly. Read $source instead.
     */
    #[ORM\Column(name: 'source', length: 20, nullable: true, enumType: CardSourceKind::class)]
    private ?CardSourceKind $sourceKind = null;

    /** No foreign key, so the source survives the delete of the run. */
    #[ORM\Column(name: 'source_run_id', type: UuidType::NAME, nullable: true)]
    private ?Uuid $sourceRunId = null;

    /** No foreign key, for the same reason. */
    #[ORM\Column(name: 'source_run_card_id', type: UuidType::NAME, nullable: true)]
    private ?Uuid $sourceRunCardId = null;

    /** Where the card came from, falling back to the reporter on a row with no stored source. */
    public CardSource $source {
        get => null === $this->sourceKind
            ? CardSource::fromReporter($this->reporter)
            : new CardSource($this->sourceKind, $this->sourceRunId, $this->sourceRunCardId);
    }

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\JoinColumn(name: 'column_id', nullable: false)]
        #[ORM\ManyToOne(targetEntity: BoardColumn::class)]
        public BoardColumn $column,

        #[ORM\Column(length: self::MAX_TITLE_LENGTH)]
        public string $title,

        #[ORM\Column(type: Types::TEXT)]
        public string $body,

        /** The short number a person says out loud, counting from 1 inside the project. Never unique across projects. */
        #[ORM\Column]
        public readonly int $number,

        /** The key of a type the project's workflow template declares. */
        #[ORM\Column(length: 20)]
        public string $type = 'feature',

        /** The column release 2 drops. Every write sets it, so an older image still reads the row. */
        #[ORM\Column(length: 20, enumType: Actor::class)]
        public readonly Actor $origin = Actor::Agent,

        /** Rank inside the card's column, counting from 0. A terminal column ignores it. */
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
        /* Null derives the source from $origin. */
        ?CardSource $source = null,
    ) {
        $source ??= CardSource::fromReporter($this->origin);
        $this->sourceKind = $source->kind;
        $this->sourceRunId = $source->runId;
        $this->sourceRunCardId = $source->runCardId;
        $this->storedReporter = $this->origin;
        $this->pullRequests = new ArrayCollection();
        $this->documents = new ArrayCollection();
        $this->updatedAt = $this->createdAt;
    }

    /** The text an edit form opened with, so a save can tell whether someone changed it since. */
    public static function contentFingerprint(string $title, string $body): string
    {
        return hash('sha256', self::normalText($title)."\0".self::normalText($body));
    }

    /**
     * Text as the web form submits it: trimmed, with Unix line ends. An MCP
     * tool stores a body as given, so compare text in this form, never raw.
     */
    public static function normalText(string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }

    /** What the workflow reads about this card when it decides on a move. */
    public function snapshot(): CardSnapshot
    {
        return new CardSnapshot(
            $this->id ?? throw new \LogicException('A stored card has an id.'),
            $this->project->id ?? throw new \LogicException('A stored project has an id.'),
            $this->number,
            $this->type,
            $this->column->ref(),
            $this->parent?->id,
            $this->parent?->number,
            $this->parent?->column->ref(),
        );
    }

    /** Whether the board draws this card as a lane of its own. */
    public function drawsLane(CardTypes $types): bool
    {
        return $types->get($this->type)->lane && $this->laneEnabled && !$this->column->terminal;
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
