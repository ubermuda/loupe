<?php

declare(strict_types=1);

namespace App\Module\Review\Entity;

use App\Doctrine\SearchLanguage;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Repository\DocumentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use MartinGeorgiev\Doctrine\DBAL\Type as PostgresType;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DocumentRepository::class)]
// No access method: DBAL's Postgres platform ignores index flags, and the
// migration creates it USING gin. Do not "fix" this with flags: ['gin'] — the
// comparator would emit a DROP plus a plain CREATE INDEX, silently downgrading
// it to a B-tree index that @@ never uses.
#[ORM\Index(name: 'idx_documents_search_vector', columns: ['search_vector'])]
// Read by the search query, which asks a project which languages it holds
// before it builds one constant tsquery per language.
#[ORM\Index(name: 'idx_documents_project_search_language', columns: ['project_id', 'search_language'])]
#[ORM\Table(name: 'documents')]
// Postgres treats NULLs as distinct here, so every document outside a series
// keeps a (NULL, NULL) pair of its own and only real numbering collides.
#[ORM\UniqueConstraint(name: 'uniq_document_series_ordinal', columns: ['series_id', 'series_ordinal'])]
class Document
{
    /** Mirrors the title column's length so callers can reject an over-long title before Postgres does. */
    public const int MAX_TITLE_LENGTH = 255;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(enumType: DocumentStatus::class)]
    public DocumentStatus $status = DocumentStatus::InReview;

    /**
     * Set when the document is archived, cleared when it is restored. Kept
     * separate from $status because the two are orthogonal: an approved
     * document can be archived, and so can one still in review.
     */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $archivedAt = null;

    /**
     * Why the document was archived, when whoever archived it said so. Only the
     * MCP tool requires a reason; archiving from the app never sets one, so null
     * is the ordinary case rather than missing data. Cleared on restore, so a
     * live document never carries the reason it was once put away for.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $archiveReason = null;

    /**
     * Title and current-version markdown, stemmed and weighted, as one searchable
     * vector. It lives here rather than on DocumentVersion because searching every
     * historical version would return a document for text it no longer contains,
     * and because a list query already filters this table — one row per document
     * keeps the GIN scan and the project/archived/status predicates together.
     *
     * The cost is on reads: the column is now in the SELECT list of every Document
     * query in the app, and the paginator projects it once more per matching row.
     * A 1:1 document_search table would keep it out of those reads, at the price of
     * a join on the one query that wants it.
     *
     * Only Postgres can build a tsvector, so the ORM never writes this column:
     * DocumentSearchIndexer maintains it, and the mapping exists so DQL can name
     * it. Null until a document is next written — see the backfill migration.
     */
    #[ORM\Column(name: 'search_vector', type: PostgresType::TSVECTOR, nullable: true, insertable: false, updatable: false)]
    public ?string $searchVector = null;

    /** @var Collection<int, DocumentVersion> */
    #[ORM\OneToMany(targetEntity: DocumentVersion::class, mappedBy: 'document', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['versionNumber' => 'ASC'])]
    public Collection $versions;

    /**
     * No cascade and no orphanRemoval: a tag is project vocabulary that outlives
     * the documents carrying it, so dropping it from one document must not
     * delete the row.
     *
     * Neither join column carries nullable: false — Doctrine ignores it on a
     * many-to-many and logs a deprecation on every mapping read. They are the
     * join table's composite primary key, so they are NOT NULL regardless.
     *
     * @var Collection<int, Tag>
     */
    #[ORM\InverseJoinColumn(name: 'tag_id')]
    #[ORM\JoinColumn(name: 'document_id')]
    #[ORM\JoinTable(name: 'document_tags')]
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\OrderBy(['name' => 'ASC'])]
    public Collection $tags;

    /**
     * The series this document is one item of, and its place in it. Both are
     * set together: a series without an ordinal cannot be read in order, and an
     * ordinal without a series numbers nothing. DocumentSeriesApplier is the one
     * writer, so the pair cannot drift.
     *
     * No cascade and no orphanRemoval: a series is project vocabulary that
     * outlives the documents in it.
     */
    #[ORM\JoinColumn(name: 'series_id', nullable: true)]
    #[ORM\ManyToOne(targetEntity: Series::class)]
    public ?Series $series = null;

    #[ORM\Column(name: 'series_ordinal', nullable: true)]
    public ?int $seriesOrdinal = null;

    /**
     * The documents this one points at. A reference targets the document rather
     * than one of its versions, so it keeps resolving — to whatever is current —
     * once the target is revised.
     *
     * @var Collection<int, self>
     */
    #[ORM\InverseJoinColumn(name: 'target_document_id')]
    #[ORM\JoinColumn(name: 'source_document_id')]
    #[ORM\JoinTable(name: 'document_references')]
    #[ORM\ManyToMany(targetEntity: self::class, inversedBy: 'referencedBy')]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    public Collection $references;

    /**
     * Derived from the owning side above, so one row keeps both ends navigable.
     * Doctrine populates it when the document is loaded and never at write
     * time: adding to another document's $references leaves this collection
     * stale for the rest of the request.
     *
     * @var Collection<int, self>
     */
    #[ORM\ManyToMany(targetEntity: self::class, mappedBy: 'references')]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    public Collection $referencedBy;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public readonly User $owner,

        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\Column(length: self::MAX_TITLE_LENGTH)]
        public string $title,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),

        /**
         * The configuration $searchVector above is built with, and the one the
         * query is parsed in for this row. Both sides must read it off the same
         * row: a vector stemmed as French and a query parsed as English never
         * meet. Assign a new value and the vector is stale until
         * DocumentSearchIndexer::index() runs again.
         */
        #[ORM\Column(name: 'search_language', length: 20, enumType: SearchLanguage::class, options: ['default' => SearchLanguage::DEFAULT->value])]
        public SearchLanguage $searchLanguage = SearchLanguage::DEFAULT,
    ) {
        $this->versions = new ArrayCollection();
        $this->tags = new ArrayCollection();
        $this->references = new ArrayCollection();
        $this->referencedBy = new ArrayCollection();
    }

    public function addVersion(string $markdown, string $renderedHtml, ?string $description = null): DocumentVersion
    {
        $version = new DocumentVersion($this, $this->versions->count() + 1, $markdown, $renderedHtml, $description);
        $this->versions->add($version);

        return $version;
    }

    public function currentVersion(): DocumentVersion
    {
        return $this->versions->last() ?: throw new \LogicException('Document has no versions.');
    }

    /**
     * Adds an outgoing reference and keeps the target's inverse side in step.
     *
     * Doctrine fills $referencedBy at load time and never at write time, so
     * touching $references directly leaves the target stale for the rest of the
     * request. That was invisible while nothing read the inverse side in a
     * request that wrote the owning one — document_get returning incoming
     * references is exactly what ends that.
     */
    public function addReference(self $target): void
    {
        if ($this->references->contains($target)) {
            return;
        }

        $this->references->add($target);
        $target->referencedBy->add($this);
    }

    /** Drops every outgoing reference, keeping each target's inverse side in step. */
    public function clearReferences(): void
    {
        foreach ($this->references as $target) {
            $target->referencedBy->removeElement($this);
        }

        $this->references->clear();
    }
}
