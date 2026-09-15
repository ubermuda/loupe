<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

use App\Module\Review\Entity\Document;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One document linked to one inbox item. Both keys cascade on delete, so Review
 * removes a document without knowing the link exists.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inbox_item_documents')]
#[ORM\UniqueConstraint(name: 'uniq_inbox_item_document', columns: ['item_id', 'document_id'])]
class InboxItemDocument
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: InboxItem::class, inversedBy: 'documents')]
        public readonly InboxItem $item,

        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Document::class)]
        public readonly Document $document,

        #[ORM\Column]
        public readonly \DateTimeImmutable $linkedAt = new \DateTimeImmutable(),
    ) {
    }
}
