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

    /**
     * Status is a property of the thread, carried by its root comment. On a reply
     * it stays at its default and is not the thread's status — read $threadStatus.
     */
    #[ORM\Column(length: 20, enumType: CommentStatus::class)]
    public CommentStatus $status = CommentStatus::Pending;

    #[ORM\Column]
    public bool $orphaned = false;

    /** The status of the thread this comment belongs to. */
    public CommentStatus $threadStatus {
        get => null !== $this->parent ? $this->parent->status : $this->status;
    }

    /**
     * True when this comment proposes an edit — a strike or a rewording. Both are
     * the same thing underneath, a replacement for the anchored passage.
     */
    public bool $isSuggestion {
        get => null !== $this->replacement;
    }

    /** True for a strike: a suggestion whose replacement is nothing at all. */
    public bool $isStrike {
        get => '' === $this->replacement;
    }

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

        // What the anchored passage should become. Three states, and the empty
        // string is NOT the same as null: null is an ordinary prose comment
        // proposing no edit, '' is a strike (replace the passage with nothing),
        // and a non-empty value is a suggested rewording. Read it through
        // $isSuggestion/$isStrike rather than truthiness — '' and null are both
        // falsy in Twig and PHP, which would collapse two of the three states.
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        public readonly ?string $replacement = null,
    ) {
    }
}
