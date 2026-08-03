<?php

declare(strict_types=1);

namespace App\Module\Review\Entity;

use App\Module\Review\Repository\DocumentVersionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DocumentVersionRepository::class)]
#[ORM\Table(name: 'document_versions')]
#[ORM\UniqueConstraint(name: 'uniq_document_version_number', columns: ['document_id', 'version_number'])]
class DocumentVersion
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    /** @var Collection<int, Comment> */
    #[ORM\OneToMany(targetEntity: Comment::class, mappedBy: 'version', cascade: ['persist'])]
    public Collection $comments;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Document::class, inversedBy: 'versions')]
        public readonly Document $document,

        #[ORM\Column]
        public readonly int $versionNumber,

        #[ORM\Column(type: Types::TEXT)]
        public readonly string $markdownSource,

        #[ORM\Column(type: Types::TEXT)]
        public readonly string $renderedHtml,

        /** What this revision changed, written once when the version is created. */
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        public readonly ?string $description = null,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        $this->comments = new ArrayCollection();
    }

    /**
     * Returns the plain-text representation of the rendered HTML — the same string
     * the browser's textContent property yields: strip_tags first, then decode HTML
     * entities. This is the SHARED BASIS for all anchor offsets:
     *   - AddCommentHandler uses it when creating anchors
     *   - ReanchoringService uses it when resolving and re-creating anchors
     *   - The JS controller computes offsets by walking text nodes (which also
     *     gives textContent, NOT innerText — innerText collapses whitespace and
     *     would desync from this basis).
     *
     * Offsets and context windows are counted in characters on both sides. PHP
     * counts codepoints and JavaScript counts UTF-16 code units, so the two agree
     * throughout the Basic Multilingual Plane — including accented Latin, Greek,
     * Cyrillic and CJK — and diverge only on astral characters such as emoji.
     */
    public function plainText(): string
    {
        return html_entity_decode(strip_tags($this->renderedHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
