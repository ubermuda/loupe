<?php

declare(strict_types=1);

namespace App\Module\Readiness\Entity;

use App\Module\Board\Entity\CardType;
use App\Module\Readiness\Repository\DiscoveryProposalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** A card that a discovery report proposes. The owner ticks it in the report, and approval turns it into a card. */
#[ORM\Entity(repositoryClass: DiscoveryProposalRepository::class)]
#[ORM\Index(name: 'idx_discovery_proposals_run', columns: ['run_id'])]
#[ORM\Table(name: 'discovery_proposals')]
class DiscoveryProposal
{
    public const int MAX_KEY_LENGTH = 64;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    /** Set once the approval made the card, so a second approval makes no duplicate. */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    public ?Uuid $createdCardId = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: DiscoveryRun::class)]
        public readonly DiscoveryRun $run,

        /** The 0-based index of the option in the decision fence, or null when the proposal has no tick box. */
        #[ORM\Column(nullable: true)]
        public readonly ?int $position,

        #[ORM\Column(name: 'proposal_key', length: self::MAX_KEY_LENGTH)]
        public readonly string $key,

        #[ORM\Column(length: 255)]
        public readonly string $title,

        #[ORM\Column(length: 20, enumType: CardType::class)]
        public readonly CardType $type,

        #[ORM\Column(type: Types::TEXT)]
        public readonly string $body,

        /** The number of an open card that covers the proposal already. */
        #[ORM\Column(nullable: true)]
        public readonly ?int $openCardNumber = null,
    ) {
    }
}
