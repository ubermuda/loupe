<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** One item inside one ask. An item can sit in several asks, and one answer counts toward each. */
#[ORM\Entity]
#[ORM\Table(name: 'inbox_ask_items')]
#[ORM\UniqueConstraint(name: 'uniq_inbox_ask_item', columns: ['ask_id', 'item_id'])]
class InboxAskItem
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    /** When the ask's own session read the item after the ask closed. */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $readAt = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: InboxAsk::class, inversedBy: 'items')]
        public readonly InboxAsk $ask,

        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: InboxItem::class)]
        public readonly InboxItem $item,

        #[ORM\Column]
        public readonly \DateTimeImmutable $addedAt = new \DateTimeImmutable(),
    ) {
    }
}
