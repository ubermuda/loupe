<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

use App\Module\Account\Entity\User;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One row of a card's history, written once and never changed.
 *
 * The index is ascending: Postgres reads it backwards for the newest-first list.
 */
#[ORM\Entity(repositoryClass: CardEventRepository::class, readOnly: true)]
#[ORM\Index(name: 'idx_board_card_events_card_occurred', columns: ['card_id', 'occurred_at', 'id'])]
#[ORM\Table(name: 'board_card_events')]
class CardEvent
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    /**
     * @param array<string, mixed> $detail
     */
    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Card::class)]
        public readonly Card $card,

        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\Column(length: 20, enumType: CardEventKind::class)]
        public readonly CardEventKind $kind,

        #[ORM\Column(length: 20, enumType: CardReporter::class)]
        public readonly CardReporter $actorKind,

        /** The person behind a human or agent change. A deleted account leaves the row with no one. */
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public ?User $actorUser = null,

        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
        public array $detail = [],

        #[ORM\Column]
        public readonly \DateTimeImmutable $occurredAt = new \DateTimeImmutable(),
    ) {
    }

    /**
     * The label as stored: a seeded column holds a translation key.
     *
     * @return array{id: string, label: string, slug: string}
     */
    public static function columnDetail(BoardColumn $column): array
    {
        return ['id' => (string) $column->id, 'label' => $column->label, 'slug' => $column->slug];
    }
}
