<?php

declare(strict_types=1);

namespace App\Module\Review\Entity;

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
    }
}
