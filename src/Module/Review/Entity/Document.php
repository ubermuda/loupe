<?php

declare(strict_types=1);

namespace App\Module\Review\Entity;

use App\Doctrine\TsVectorType;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Repository\DocumentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DocumentRepository::class)]
// Declared without an access method because DBAL's Postgres platform ignores
// index flags; the migration creates it USING gin, and this mapping exists so
// the comparator sees an index it already knows about rather than dropping it.
#[ORM\Index(name: 'idx_documents_search_vector', columns: ['search_vector'])]
#[ORM\Table(name: 'documents')]
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
     * Title and current-version markdown, stemmed and weighted, as one searchable
     * vector. It lives here rather than on DocumentVersion because searching every
     * historical version would return a document for text it no longer contains,
     * and because a list query already filters this table — one row per document
     * keeps the GIN scan and the project/archived/status predicates together.
     *
     * Only Postgres can build a tsvector, so the ORM never writes this column:
     * DocumentSearchIndexer maintains it, and the mapping exists so DQL can name
     * it. Null until a document is next written — see the backfill migration.
     */
    #[ORM\Column(name: 'search_vector', type: TsVectorType::NAME, nullable: true, insertable: false, updatable: false)]
    public ?string $searchVector = null;

    /** @var Collection<int, DocumentVersion> */
    #[ORM\OneToMany(targetEntity: DocumentVersion::class, mappedBy: 'document', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['versionNumber' => 'ASC'])]
    public Collection $versions;

    /**
     * Owning side of the only many-to-many in the app. No cascade and no
     * orphanRemoval: a tag is project vocabulary that outlives the documents
     * carrying it, so dropping it from one document must not delete the row.
     *
     * @var Collection<int, Tag>
     */
    #[ORM\InverseJoinColumn(name: 'tag_id', nullable: false)]
    #[ORM\JoinColumn(name: 'document_id', nullable: false)]
    #[ORM\JoinTable(name: 'document_tags')]
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\OrderBy(['name' => 'ASC'])]
    public Collection $tags;

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
    ) {
        $this->versions = new ArrayCollection();
        $this->tags = new ArrayCollection();
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
}
