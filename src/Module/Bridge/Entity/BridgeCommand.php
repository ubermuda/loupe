<?php

declare(strict_types=1);

namespace App\Module\Bridge\Entity;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\ValueObject\BridgeCommandCause;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A request from a person or from Loupe to one bridge about one of its worker
 * runs. The outbox carries it to the bridge, and the heartbeat reply carries it
 * again until the bridge settles it or it expires.
 */
#[ORM\Entity(repositoryClass: BridgeCommandRepository::class)]
// The heartbeat reply reads the pending commands of one bridge.
#[ORM\Index(name: 'idx_bridge_commands_bridge_state', columns: ['owner_id', 'bridge_id', 'state'])]
#[ORM\Index(name: 'idx_bridge_commands_state_expires', columns: ['state', 'expires_at'])]
#[ORM\Table(name: 'bridge_commands')]
// One run holds one pending command per bridge. The predicate is written the way
// Postgres stores it, so migrate-diff stays quiet.
#[ORM\UniqueConstraint(name: self::PENDING_RUN_INDEX, columns: ['worker_run_id', 'bridge_id'], options: ['where' => "((state)::text = 'pending'::text)"])]
class BridgeCommand
{
    public const string PENDING_RUN_INDEX = 'uniq_bridge_command_pending_run';

    public const int MAX_REASON_LENGTH = 1000;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(name: 'state', length: 20, enumType: BridgeCommandState::class)]
    public BridgeCommandState $state = BridgeCommandState::Pending;

    #[ORM\Column(name: 'settled_at', nullable: true)]
    public ?\DateTimeImmutable $settledAt = null;

    /** The context of the work request of the run, so a rerun fills the values its first run had. */
    public WorkRequestContext $context {
        get => WorkRequestContext::fromArray($this->contextData);
        set {
            $this->contextData = $value->toArray();
        }
    }

    /** @var array<mixed>|null */
    #[ORM\Column(name: 'context', type: Types::JSON, nullable: true)]
    private ?array $contextData = null;

    /** The model and the effort of the work request of the run, so a resume runs as its first run did. */
    #[ORM\Column(name: 'model', length: WorkRequest::MAX_MODEL_LENGTH, nullable: true)]
    public ?string $model = null;

    #[ORM\Column(name: 'effort', length: 16, nullable: true)]
    public ?string $effort = null;

    public function __construct(
        /** The owner of the bridge row, which is the owner of the project. */
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public User $owner,

        #[ORM\Column(name: 'bridge_id', type: UuidType::NAME)]
        public Uuid $bridgeId,

        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public Project $project,

        /** The run retention sweep deletes a run in bulk, so the database removes its commands. */
        #[ORM\JoinColumn(name: 'worker_run_id', nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: WorkerRun::class)]
        public WorkerRun $workerRun,

        #[ORM\Column(name: 'kind', length: 32, enumType: BridgeCommandKind::class)]
        public BridgeCommandKind $kind,

        #[ORM\JoinColumn(name: 'requested_by_id', nullable: true, onDelete: 'SET NULL')]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public ?User $requestedBy,

        #[ORM\Column(name: 'requested_at')]
        public \DateTimeImmutable $requestedAt,

        #[ORM\Column(name: 'expires_at')]
        public \DateTimeImmutable $expiresAt,

        /** The reason the person gave, until the bridge settles the command with a reason of its own. */
        #[ORM\Column(name: 'reason', type: Types::TEXT, nullable: true)]
        public ?string $reason = null,

        #[ORM\Column(name: 'cause', length: 20, enumType: BridgeCommandCause::class, options: ['default' => 'person'])]
        public BridgeCommandCause $cause = BridgeCommandCause::Person,
    ) {
    }

    /** Moves a pending command to a final state. Answers false when the command was already settled. */
    public function settle(BridgeCommandState $state, ?string $reason, \DateTimeImmutable $now): bool
    {
        if (BridgeCommandState::Pending === $state) {
            throw new \LogicException('A command settles to a final state, and pending is not one.');
        }
        if (BridgeCommandState::Pending !== $this->state) {
            return false;
        }

        $this->state = $state;
        if (null !== $reason) {
            $this->reason = mb_substr($reason, 0, self::MAX_REASON_LENGTH);
        }
        $this->settledAt = $now;

        return true;
    }
}
