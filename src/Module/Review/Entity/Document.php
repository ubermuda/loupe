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
     * The documents this one points at. A reference targets the document rather
     * than one of its versions, so it keeps resolving — to whatever is current —
     * once the target is revised.
     *
     * @var Collection<int, self>
     */
    #[ORM\InverseJoinColumn(name: 'target_document_id', nullable: false)]
    #[ORM\JoinColumn(name: 'source_document_id', nullable: false)]
    #[ORM\JoinTable(name: 'document_references')]
    #[ORM\ManyToMany(targetEntity: self::class, inversedBy: 'referencedBy')]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    public Collection $references;

    /**
     * Derived from the owning side above — nothing writes it. Both ends of a
     * reference are navigable while only one row exists to keep in step.
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
