<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** A stop on a card that the workflow engine applies and a release lifts. A card holds one active pause at most. */
#[ORM\Entity(repositoryClass: CardPauseRepository::class)]
#[ORM\Table(name: 'card_pauses')]
// The predicate is written the way Postgres stores it, so migrate-diff stays quiet.
#[ORM\UniqueConstraint(name: self::ACTIVE_CARD_INDEX, columns: ['card_id'], options: ['where' => '(released_at IS NULL)'])]
class CardPause
{
    public const string ACTIVE_CARD_INDEX = 'uniq_card_pause_active_card';

    public const int MAX_REASON_LENGTH = 64;

    /** A reason code, never text a person wrote. */
    public const string REASON_PATTERN = '/^[a-z][a-z0-9-]{0,63}$/D';

    public const int MAX_RULE_ID_LENGTH = 100;

    /** The id of the template rule that paused the card. */
    public const string RULE_ID_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,99}$/D';

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(name: 'released_at', nullable: true)]
    public ?\DateTimeImmutable $releasedAt = null;

    #[ORM\Column(name: 'release_reason', length: self::MAX_REASON_LENGTH, nullable: true)]
    public ?string $releaseReason = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Card::class)]
        public Card $card,

        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public Project $project,

        #[ORM\Column(name: 'reason', length: self::MAX_REASON_LENGTH)]
        public string $reason,

        #[ORM\Column(name: 'rule_id', length: self::MAX_RULE_ID_LENGTH)]
        public string $ruleId,

        #[ORM\Column(name: 'kind', length: 20, enumType: CardPauseKind::class)]
        public CardPauseKind $kind,

        #[ORM\Column(name: 'created_at')]
        public \DateTimeImmutable $createdAt,
    ) {
        if (1 !== preg_match(self::REASON_PATTERN, $reason)) {
            throw new \LogicException('A card pause reason is a code.');
        }
        if (1 !== preg_match(self::RULE_ID_PATTERN, $ruleId)) {
            throw new \LogicException('A card pause names the id of a rule.');
        }
    }

    /** Lifts the pause. Answers false when it was already released. */
    public function release(string $reason, \DateTimeImmutable $now): bool
    {
        if (1 !== preg_match(self::REASON_PATTERN, $reason)) {
            throw new \LogicException('A card pause release reason is a code.');
        }
        if (null !== $this->releasedAt) {
            return false;
        }

        $this->releasedAt = $now;
        $this->releaseReason = $reason;

        return true;
    }
}
