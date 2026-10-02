<?php

declare(strict_types=1);

namespace App\Module\Bridge\Entity;

use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Work on one card that one bridge claims. The outbox carries it to the
 * bridges, and the heartbeat reply offers it again while it stays open. The
 * card id is a plain value, so this module needs nothing from the board.
 */
#[ORM\Entity(repositoryClass: WorkRequestRepository::class)]
// The heartbeat reply reads the open requests of the projects a bridge follows.
#[ORM\Index(name: 'idx_work_requests_project_state', columns: ['project_id', 'state'])]
#[ORM\Index(name: 'idx_work_requests_state_lease', columns: ['state', 'lease_until'])]
#[ORM\Table(name: 'work_requests')]
// One card holds one live request of a kind. The predicate is written the way
// Postgres stores it, so migrate-diff stays quiet.
#[ORM\UniqueConstraint(name: self::LIVE_CARD_KIND_INDEX, columns: ['card_id', 'kind'], options: ['where' => "((state)::text = ANY (ARRAY[('open'::character varying)::text, ('claimed'::character varying)::text]))"])]
class WorkRequest
{
    public const string LIVE_CARD_KIND_INDEX = 'uniq_work_request_live_card_kind';

    public const int MAX_KIND_LENGTH = 40;

    /** A kind of work, such as design, implement, fix or merge. */
    public const string KIND_PATTERN = '/^[a-z][a-z0-9-]{0,39}$/D';

    public const int MAX_RULE_ID_LENGTH = 100;

    /** The id of the template rule that opened the request. */
    public const string RULE_ID_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,99}$/D';

    public const int MAX_REASON_LENGTH = 64;

    /** A reason code, never text a person wrote. */
    public const string REASON_PATTERN = '/^[a-z][a-z0-9-]{0,63}$/D';

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(name: 'state', length: 20, enumType: WorkRequestState::class)]
    public WorkRequestState $state = WorkRequestState::Open;

    /** The bridge that holds the claim, or that held it when the request settled. */
    #[ORM\Column(name: 'bridge_id', type: UuidType::NAME, nullable: true)]
    public ?Uuid $bridgeId = null;

    /** The fencing token of the current claim. A bridge proves its claim with it. */
    #[ORM\Column(name: 'claim_token', type: UuidType::NAME, nullable: true)]
    public ?Uuid $claimToken = null;

    #[ORM\Column(name: 'lease_until', nullable: true)]
    public ?\DateTimeImmutable $leaseUntil = null;

    /** How many times a bridge claimed the request. */
    #[ORM\Column(name: 'claims', options: ['default' => 0])]
    public int $claims = 0;

    #[ORM\Column(name: 'settled_at', nullable: true)]
    public ?\DateTimeImmutable $settledAt = null;

    #[ORM\Column(name: 'reason', length: self::MAX_REASON_LENGTH, nullable: true)]
    public ?string $reason = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public Project $project,

        #[ORM\Column(name: 'card_id', type: UuidType::NAME)]
        public Uuid $cardId,

        #[ORM\Column(name: 'card_number')]
        public int $cardNumber,

        #[ORM\Column(name: 'kind', length: self::MAX_KIND_LENGTH)]
        public string $kind,

        /** The capability a bridge must report to take the request. Null when any bridge that takes work requests can. */
        #[ORM\Column(name: 'capability', length: 40, nullable: true)]
        public ?string $capability,

        #[ORM\Column(name: 'rule_id', length: self::MAX_RULE_ID_LENGTH)]
        public string $ruleId,

        #[ORM\Column(name: 'created_at')]
        public \DateTimeImmutable $createdAt,
    ) {
    }

    /** Moves a claimed request to done or refused. Answers false when the request is not claimed. */
    public function settle(WorkRequestState $state, ?string $reason, \DateTimeImmutable $now): bool
    {
        if (WorkRequestState::Done !== $state && WorkRequestState::Refused !== $state) {
            throw new \LogicException('A work request settles to done or refused.');
        }
        if (null !== $reason && 1 !== preg_match(self::REASON_PATTERN, $reason)) {
            throw new \LogicException('A work request reason is a code.');
        }
        if (WorkRequestState::Claimed !== $this->state) {
            return false;
        }

        $this->state = $state;
        $this->reason = $reason;
        $this->settledAt = $now;

        return true;
    }

    /** Moves a live request to cancelled or expired. Answers false when the request already settled. */
    public function withdraw(WorkRequestState $state, \DateTimeImmutable $now): bool
    {
        if (WorkRequestState::Cancelled !== $state && WorkRequestState::Expired !== $state) {
            throw new \LogicException('A work request withdraws to cancelled or expired.');
        }
        if (!$this->state->isLive()) {
            return false;
        }

        $this->state = $state;
        $this->settledAt = $now;

        return true;
    }
}
