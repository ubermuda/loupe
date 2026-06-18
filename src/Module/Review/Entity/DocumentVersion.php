<?php

declare(strict_types=1);

namespace App\Module\Review\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'document_versions')]
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

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        $this->comments = new ArrayCollection();
    }
}
