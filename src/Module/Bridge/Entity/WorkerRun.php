<?php

declare(strict_types=1);

namespace App\Module\Bridge\Entity;

use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
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
// One session holds one open interactive run on a card.
#[ORM\UniqueConstraint(name: 'uniq_bridge_worker_run_interactive_open', columns: ['project_id', 'card_id', 'session_id'], options: ['where' => "(((kind)::text = 'interactive'::text) AND ((state)::text = 'running'::text))"])]
class WorkerRun
{
    /** Mirrors the cap the bridge applies to a worker's output before it reports. */
    public const int MAX_OUTPUT_LENGTH = 4000;

    public const int MAX_RULE_NAME_LENGTH = 100;

    public const int MAX_FAILURE_REASON_LENGTH = 1000;

    /** The limit applies to the extra result fields once they are encoded as JSON. */
    public const int MAX_RESULT_FIELDS_BYTES = 4000;

    /** A board column slug is text, and a 100-character label can give up to 1,700 characters. */
    public const int MAX_CARD_COLUMN_LENGTH = 2000;

    public const int MAX_RESUME_SKIPPED_LENGTH = 50;

    /** The largest value of a smallint column. */
    public const int MAX_RESUME_COUNT = 32767;

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

    /** The status the worker gave in its structured result: finished, blocked or unfinished. */
    #[ORM\Column(name: 'result_status', length: 20, nullable: true)]
    public ?string $resultStatus = null;

    /**
     * The extra fields of the structured result, which the rule defines.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(name: 'result_fields', type: Types::JSON, nullable: true)]
    public ?array $resultFields = null;

    /** Why the bridge did not resume this run, such as card_moved. */
    #[ORM\Column(name: 'resume_skipped', length: self::MAX_RESUME_SKIPPED_LENGTH, nullable: true)]
    public ?string $resumeSkipped = null;

    /** Where the WorkerRunUsage rows of the run come from. Null when an older bridge sent no usage. */
    #[ORM\Column(name: 'usage_source', length: 20, nullable: true, enumType: WorkerRunUsageSource::class)]
    public ?WorkerRunUsageSource $usageSource = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        /** The bridge that ran the work. An opaque scalar: no table holds a bridge. Null on an interactive run. */
        #[ORM\Column(name: 'bridge_id', type: UuidType::NAME, nullable: true)]
        public readonly ?Uuid $bridgeId,

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

        /** Whether the worker produced its final result. Null for a run that never ran, or from an older bridge. */
        #[ORM\Column(name: 'has_result', nullable: true)]
        public ?bool $hasResult = null,

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

        /** The run this run resumes. The link goes when that run is deleted. */
        #[ORM\JoinColumn(name: 'continues_run_id', nullable: true, onDelete: 'SET NULL')]
        #[ORM\ManyToOne(targetEntity: self::class)]
        public ?WorkerRun $continuesRun = null,

        /** The place of this run in its series of resumes, and the cap the rule set for the series. */
        #[ORM\Column(name: 'resume_index', type: Types::SMALLINT, nullable: true)]
        public ?int $resumeIndex = null,

        #[ORM\Column(name: 'resume_cap', type: Types::SMALLINT, nullable: true)]
        public ?int $resumeCap = null,

        /** The slug of the column that started the series, as the bridge saw it. */
        #[ORM\Column(name: 'card_column', type: Types::TEXT, nullable: true)]
        public ?string $cardColumn = null,

        // Rows the previous image writes are worker runs.
        #[ORM\Column(name: 'kind', length: 20, enumType: WorkerRunKind::class, options: ['default' => WorkerRunKind::Worker->value])]
        public readonly WorkerRunKind $kind = WorkerRunKind::Worker,
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

    /** @param array<string, mixed>|null $resultFields */
    public function recordOutcome(
        WorkerRunState $state,
        \DateTimeImmutable $endedAt,
        ?int $exitCode,
        ?bool $hasResult,
        ?string $failureReason,
        string $output,
        ?string $resultStatus = null,
        ?array $resultFields = null,
        ?string $resumeSkipped = null,
    ): void {
        if ($state->isOpen()) {
            throw new \LogicException(\sprintf('An outcome closes the run, and %s is open.', $state->value));
        }

        $this->state = $state;
        $this->endedAt = $endedAt;
        $this->exitCode = $exitCode;
        $this->hasResult = $hasResult;
        $this->failureReason = $failureReason;
        $this->output = $output;
        $this->resultStatus = $resultStatus;
        $this->resultFields = $resultFields;
        $this->resumeSkipped = $resumeSkipped;
    }
}
