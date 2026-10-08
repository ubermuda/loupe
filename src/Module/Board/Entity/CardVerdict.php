<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

use App\Module\Account\Entity\User;
use App\Module\Board\Repository\CardVerdictRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A reviewer's verdict on a card, sent from the widget.
 *
 * The notes are a copy taken at Send. A later edit of a note does not change them.
 */
#[ORM\Entity(repositoryClass: CardVerdictRepository::class)]
#[ORM\Index(name: 'idx_board_card_verdicts_card_created', columns: ['card_id', 'created_at'])]
#[ORM\Table(name: 'board_card_verdicts')]
class CardVerdict
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    /**
     * @param list<array{id: string, url: string, body: string, anchorCount: int}> $notes
     */
    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Card::class)]
        public readonly Card $card,

        #[ORM\Column(length: 20, enumType: CardVerdictKind::class)]
        public readonly CardVerdictKind $kind,

        /** The person who sent it. A deleted account leaves the verdict with no one. */
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public readonly ?User $reviewer,

        #[ORM\Column(type: Types::TEXT)]
        public readonly string $message,

        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
        public readonly array $notes,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }
}
