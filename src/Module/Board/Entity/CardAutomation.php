<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

use App\Module\Board\Repository\CardAutomationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** What the board automation last did on one card, and how many fix rounds it asked for. */
#[ORM\Entity(repositoryClass: CardAutomationRepository::class)]
#[ORM\Table(name: 'board_card_automations')]
class CardAutomation
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column]
    public int $fixRounds = 0;

    #[ORM\Column(length: 50, nullable: true)]
    public ?string $blockedReason = null;

    #[ORM\Column(length: 20, nullable: true, enumType: CardAutomationAction::class)]
    public ?CardAutomationAction $lastAction = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $lastActionAt = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\OneToOne(targetEntity: Card::class)]
        public readonly Card $card,
    ) {
    }
}
