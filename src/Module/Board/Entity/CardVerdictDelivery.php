<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

use App\Module\Board\Repository\CardVerdictDeliveryRepository;
use App\Module\Forge\Entity\ForgePullRequest;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** The review of one pull request that a verdict asks for. */
#[ORM\Entity(repositoryClass: CardVerdictDeliveryRepository::class)]
#[ORM\Index(name: 'idx_board_card_verdict_deliveries_state', columns: ['state'])]
#[ORM\Table(name: 'board_card_verdict_deliveries')]
class CardVerdictDelivery
{
    public const int MAX_REASON_LENGTH = 50;

    public const int MAX_REVIEW_URL_LENGTH = 512;

    /** The reviewer's GitHub connection was missing or expired when the review was due. */
    public const string REASON_CONNECTION_EXPIRED = 'connection-expired';

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(length: 20, enumType: CardVerdictDeliveryState::class)]
    public CardVerdictDeliveryState $state = CardVerdictDeliveryState::Pending;

    /** A code that says why a delivery was skipped or refused. */
    #[ORM\Column(length: self::MAX_REASON_LENGTH, nullable: true)]
    public ?string $reason = null;

    /** The GitHub URL of the review or comment that was posted. */
    #[ORM\Column(length: self::MAX_REVIEW_URL_LENGTH, nullable: true)]
    public ?string $reviewUrl = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $settledAt = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: CardVerdict::class)]
        public readonly CardVerdict $verdict,

        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: ForgePullRequest::class)]
        public readonly ForgePullRequest $pullRequest,
    ) {
    }
}
