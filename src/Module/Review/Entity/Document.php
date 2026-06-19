<?php

declare(strict_types=1);

namespace App\Module\Review\Entity;

use App\Module\Account\Entity\User;
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
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(enumType: DocumentStatus::class)]
    public DocumentStatus $status = DocumentStatus::InReview;

    /** @var Collection<int, DocumentVersion> */
    #[ORM\OneToMany(targetEntity: DocumentVersion::class, mappedBy: 'document', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['versionNumber' => 'ASC'])]
    public Collection $versions;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public readonly User $owner,

        #[ORM\Column(length: 255)]
        public string $title,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        $this->versions = new ArrayCollection();
    }

    public function addVersion(string $markdown, string $renderedHtml): DocumentVersion
    {
        $version = new DocumentVersion($this, $this->versions->count() + 1, $markdown, $renderedHtml);
        $this->versions->add($version);

        return $version;
    }

    public function currentVersion(): DocumentVersion
    {
        return $this->versions->last() ?: throw new \LogicException('Document has no versions.');
    }
}
