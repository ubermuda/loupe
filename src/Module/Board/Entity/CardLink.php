<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

use App\Module\Board\Repository\CardLinkRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One link from a source card to a target card. The target reads the inverse
 * of the stored kind, so a row never stores `BlockedBy`.
 */
#[ORM\Entity(repositoryClass: CardLinkRepository::class)]
#[ORM\Table(name: 'board_card_links')]
#[ORM\UniqueConstraint(name: 'uniq_board_card_link', columns: ['source_card_id', 'target_card_id'])]
class CardLink
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(name: 'source_card_id', nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Card::class)]
        public Card $source,

        #[ORM\JoinColumn(name: 'target_card_id', nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Card::class)]
        public Card $target,

        #[ORM\Column(length: 20, enumType: CardLinkKind::class)]
        public CardLinkKind $kind {
            set(CardLinkKind $kind) {
                if (CardLinkKind::BlockedBy === $kind) {
                    throw new \LogicException('A card link stores blocked-by as the inverse row: swap the cards and store blocks.');
                }
                $this->kind = $kind;
            }
        },

        #[ORM\Column]
        public readonly \DateTimeImmutable $linkedAt = new \DateTimeImmutable(),
    ) {
    }

    public function kindFor(Card $reader): CardLinkKind
    {
        return match (true) {
            self::same($reader, $this->source) => $this->kind,
            self::same($reader, $this->target) => $this->kind->inverse(),
            default => throw new \LogicException('The card is on neither side of this link.'),
        };
    }

    public function otherThan(Card $reader): Card
    {
        return match (true) {
            self::same($reader, $this->source) => $this->target,
            self::same($reader, $this->target) => $this->source,
            default => throw new \LogicException('The card is on neither side of this link.'),
        };
    }

    private static function same(Card $a, Card $b): bool
    {
        return $a === $b || (null !== $a->id && $a->id->equals($b->id));
    }
}
