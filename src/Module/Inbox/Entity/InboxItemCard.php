<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

use App\Module\Board\Entity\Card;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One card linked to one inbox item. Both keys cascade on delete, so Board
 * removes a card without knowing the link exists.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inbox_item_cards')]
#[ORM\UniqueConstraint(name: 'uniq_inbox_item_card', columns: ['item_id', 'card_id'])]
class InboxItemCard
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: InboxItem::class, inversedBy: 'cards')]
        public readonly InboxItem $item,

        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Card::class)]
        public readonly Card $card,

        #[ORM\Column]
        public readonly \DateTimeImmutable $linkedAt = new \DateTimeImmutable(),
    ) {
    }
}
