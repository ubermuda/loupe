<?php

declare(strict_types=1);

namespace App\Module\Insights\Entity;

use App\Module\Insights\Repository\ProposalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** One change an analysis proposes. The card it created is a scalar id, so this module needs nothing from Board. */
#[ORM\Entity(repositoryClass: ProposalRepository::class)]
#[ORM\Table(name: 'insights_proposals')]
class Proposal
{
    public const int MAX_TITLE_LENGTH = 200;

    public const int MAX_ESTIMATED_SAVING_LENGTH = 200;

    public const int MAX_DISMISS_REASON_LENGTH = 500;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(name: 'state', length: 20, enumType: ProposalState::class)]
    public ProposalState $state = ProposalState::Proposed;

    #[ORM\Column(name: 'dismiss_reason', length: self::MAX_DISMISS_REASON_LENGTH, nullable: true)]
    public ?string $dismissReason = null;

    #[ORM\Column(name: 'card_id', type: UuidType::NAME, nullable: true)]
    public ?Uuid $cardId = null;

    /** @param array<mixed>|null $payload */
    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Analysis::class)]
        public Analysis $analysis,

        #[ORM\Column(name: 'kind', length: 20, enumType: ProposalKind::class)]
        public ProposalKind $kind,

        #[ORM\Column(name: 'title', length: self::MAX_TITLE_LENGTH)]
        public string $title,

        #[ORM\Column(name: 'body', type: Types::TEXT)]
        public string $body,

        #[ORM\Column(name: 'payload', type: Types::JSON, nullable: true)]
        public ?array $payload,

        #[ORM\Column(name: 'estimated_saving', length: self::MAX_ESTIMATED_SAVING_LENGTH, nullable: true)]
        public ?string $estimatedSaving,

        #[ORM\Column(name: 'position')]
        public int $position,
    ) {
    }
}
