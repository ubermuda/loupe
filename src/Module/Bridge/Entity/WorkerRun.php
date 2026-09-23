<?php

declare(strict_types=1);

namespace App\Module\Bridge\Entity;

use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use MartinGeorgiev\Doctrine\DBAL\Type as PostgresType;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One run of one CLI bridge worker. The row holds the run's current state, and
 * each report the bridge sends for the run can change it.
 * WorkerRunStateChange holds the history.
 */
#[ORM\Entity(repositoryClass: WorkerRunRepository::class)]
// The page reads one project newest first.
#[ORM\Index(name: 'idx_bridge_worker_runs_project_received', columns: ['project_id', 'received_at'])]
// The retention sweep names no project, so it cannot use the composite index
// above and needs received_at as a leading column of its own.
#[ORM\Index(name: 'idx_bridge_worker_runs_received', columns: ['received_at'])]
// No access method: DBAL's Postgres platform ignores index flags, and the
// migration creates it USING gin. flags: ['gin'] would make the comparator emit
// a DROP plus a plain CREATE INDEX, downgrading it to a B-tree that @@ never uses.
#[ORM\Index(name: 'idx_bridge_worker_runs_search_vector', columns: ['search_vector'])]
// A resume finds the card of a session through its run.
#[ORM\Index(name: 'idx_bridge_worker_runs_session', columns: ['session_id'])]
// The sweep that times out a quiet bridge's runs reads the open states.
#[ORM\Index(name: 'idx_bridge_worker_runs_state', columns: ['state'])]
#[ORM\Table(name: 'bridge_worker_runs')]
// A bridge that sends no run key reports a finished run once, and retries it
// when it never saw the response. The start then identifies the run. The
// predicate is written the way Postgres stores it, so migrate-diff stays quiet.
#[ORM\UniqueConstraint(name: 'uniq_bridge_worker_run_report', columns: ['project_id', 'bridge_id', 'card_id', 'started_at'], options: ['where' => '(run_key IS NULL)'])]
#[ORM\UniqueConstraint(name: 'uniq_bridge_worker_run_key', columns: ['project_id', 'bridge_id', 'run_key'])]
class WorkerRun
{
    /** Mirrors the cap the bridge applies to a worker's output before it reports. */
    public const int MAX_OUTPUT_LENGTH = 4000;

    public const int MAX_RULE_NAME_LENGTH = 100;

    public const int MAX_FAILURE_REASON_LENGTH = 1000;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    /**
     * The card number, the rule name and the output as one searchable vector.
     *
     * Only Postgres can build a tsvector, so the ORM never writes this column:
     * WorkerRunSearchIndexer maintains it, and the mapping exists so DQL can
     * name it.
     */
    #[ORM\Column(name: 'search_vector', type: PostgresType::TSVECTOR, nullable: true, insertable: false, updatable: false)]
    public ?string $searchVector = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        /** The bridge that ran the work. An opaque scalar: no table holds a bridge. */
        #[ORM\Column(name: 'bridge_id', type: UuidType::NAME)]
        public readonly Uuid $bridgeId,

        /** A scalar, never a foreign key, so a deleted card leaves its run history intact. */
        #[ORM\Column(name: 'card_id', type: UuidType::NAME)]
        public readonly Uuid $cardId,

        #[ORM\Column(name: 'card_number')]
        public readonly int $cardNumber,

        #[ORM\Column(name: 'rule_name', length: self::MAX_RULE_NAME_LENGTH)]
        public readonly string $ruleName,

        // The default fills a row that the previous image writes during a deploy or after a rollback.
        #[ORM\Column(name: 'state', length: 20, enumType: WorkerRunState::class, options: ['default' => WorkerRunState::Failed->value])]
        public WorkerRunState $state,

        /** The id the bridge gives a run when it queues the event. Null on a run an older bridge reported. */
        #[ORM\Column(name: 'run_key', type: UuidType::NAME, nullable: true)]
        public readonly ?Uuid $runKey = null,

        /** The claude session the worker ran as. The bridge generates it when it starts the worker. */
        #[ORM\Column(name: 'session_id', type: UuidType::NAME, nullable: true)]
        public ?Uuid $sessionId = null,

        #[ORM\Column(name: 'started_at', nullable: true)]
        public ?\DateTimeImmutable $startedAt = null,

        #[ORM\Column(name: 'ended_at', nullable: true)]
        public ?\DateTimeImmutable $endedAt = null,

        /** Null means the process never ran, and $failureReason then says why. */
        #[ORM\Column(name: 'exit_code', nullable: true)]
        public ?int $exitCode = null,

        #[ORM\Column(name: 'failure_reason', type: Types::TEXT, nullable: true)]
        public ?string $failureReason = null,

        #[ORM\Column(name: 'output', type: Types::TEXT)]
        public string $output = '',

        /**
         * The server clock when the first report of the run arrived. The
         * retention sweep reads this column.
         */
        #[ORM\Column(name: 'received_at')]
        public readonly \DateTimeImmutable $receivedAt = new \DateTimeImmutable(),
    ) {
    }

    /** For a state that carries no data of its own. */
    public function moveTo(WorkerRunState $state): void
    {
        $this->state = $state;
    }

    public function markRunning(Uuid $sessionId, \DateTimeImmutable $startedAt): void
    {
        $this->state = WorkerRunState::Running;
        $this->sessionId = $sessionId;
        $this->startedAt = $startedAt;
    }

    public function recordOutcome(
        WorkerRunState $state,
        \DateTimeImmutable $endedAt,
        ?int $exitCode,
        ?string $failureReason,
        string $output,
    ): void {
        if ($state->isOpen()) {
            throw new \LogicException(\sprintf('An outcome closes the run, and %s is open.', $state->value));
        }

        $this->state = $state;
        $this->endedAt = $endedAt;
        $this->exitCode = $exitCode;
        $this->failureReason = $failureReason;
        $this->output = $output;
    }
}
