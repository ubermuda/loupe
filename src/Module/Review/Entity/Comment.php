<?php

declare(strict_types=1);

namespace App\Module\Review\Entity;

use App\Module\Account\Entity\User;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\ValueObject\Anchor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CommentRepository::class)]
#[ORM\Table(name: 'comments')]
class Comment
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column]
    public bool $resolved = false;

    #[ORM\Column]
    public bool $orphaned = false;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: DocumentVersion::class, inversedBy: 'comments')]
        public readonly DocumentVersion $version,

        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public readonly User $author,

        #[ORM\Column(type: Types::TEXT)]
        public string $body,

        #[ORM\Embedded(class: Anchor::class)]
        public readonly Anchor $anchor,

        #[ORM\JoinColumn(nullable: true)]
        #[ORM\ManyToOne(targetEntity: self::class)]
        public readonly ?Comment $parent = null,
    ) {
    }
}
