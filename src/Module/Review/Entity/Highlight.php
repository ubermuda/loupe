<?php

declare(strict_types=1);

namespace App\Module\Review\Entity;

use App\Module\Review\ValueObject\Anchor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A passage the agent marked as worth reading first.
 *
 * It is deliberately NOT a comment: no body, no author, no status, no parent,
 * and nothing to reply to. Its whole content is the span it covers, which is why
 * it carries an {@see Anchor} and nothing else — a reviewer sees a tinted stretch
 * of prose and reads it, and there is no thread for them to answer.
 *
 * Highlights belong to a single {@see DocumentVersion} and are not carried onto
 * the next one. An orphaned comment still has a body worth showing after a
 * revision; an orphaned highlight is pure position with nothing left to display,
 * so a revision drops the set and the agent re-states what matters in the text it
 * has just written.
 */
#[ORM\Entity]
#[ORM\Table(name: 'document_highlights')]
class Highlight
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: DocumentVersion::class, inversedBy: 'highlights')]
        public readonly DocumentVersion $version,

        #[ORM\Embedded(class: Anchor::class)]
        public readonly Anchor $anchor,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }
}
