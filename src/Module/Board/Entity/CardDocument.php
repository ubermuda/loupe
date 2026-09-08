<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

use App\Module\Review\Entity\Document;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One document linked to one card.
 *
 * The row lives in Board because the arkitect rule fences Board as a leaf:
 * Review must not learn that cards exist. A document therefore does not list
 * its cards, and adding that would need the interface pattern the site-review
 * context label already uses.
 *
 * Both sides cascade on delete, so neither module has to know the link is
 * there when it removes its own row.
 */
#[ORM\Entity]
#[ORM\Table(name: 'board_card_documents')]
#[ORM\UniqueConstraint(name: 'uniq_board_card_document', columns: ['card_id', 'document_id'])]
class CardDocument
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Card::class, inversedBy: 'documents')]
        public readonly Card $card,

        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Document::class)]
        public readonly Document $document,

        #[ORM\Column]
        public readonly \DateTimeImmutable $linkedAt = new \DateTimeImmutable(),
    ) {
    }
}
