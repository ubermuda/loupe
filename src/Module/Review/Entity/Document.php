<?php

declare(strict_types=1);

namespace App\Module\Review\Entity;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Repository\DocumentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DocumentRepository::class)]
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
}
