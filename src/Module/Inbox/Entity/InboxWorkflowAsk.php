<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

use App\Module\Inbox\Repository\InboxWorkflowAskRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Ties a workflow item to the card and the rule that asked. The card id has no
 * foreign key, so the row outlives a card delete and Loupe can still close the item.
 */
#[ORM\Entity(repositoryClass: InboxWorkflowAskRepository::class)]
#[ORM\Index(name: 'idx_inbox_workflow_asks_card', columns: ['card_id'])]
#[ORM\Table(name: 'inbox_workflow_asks')]
class InboxWorkflowAsk
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\OneToOne(targetEntity: InboxItem::class)]
        public readonly InboxItem $item,

        #[ORM\Column(name: 'card_id', type: UuidType::NAME)]
        public readonly Uuid $cardId,

        #[ORM\Column(length: 100)]
        public readonly string $ruleId,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        if (InboxItemKind::Workflow !== $item->kind) {
            throw new \InvalidArgumentException('A workflow ask belongs to a workflow inbox item.');
        }
    }
}
