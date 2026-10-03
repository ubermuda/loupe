<?php

declare(strict_types=1);

namespace App\Module\Bridge\Entity;

use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunReason;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Module\Project\Entity\Project;
use App\Security\ProjectScopedSubject;
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
// A bridge that sends no run key reports a finished worker run once, and
// retries it when it never saw the response. The start then identifies the run.
// The predicate is written the way Postgres stores it, so migrate-diff stays quiet.
#[ORM\UniqueConstraint(name: 'uniq_bridge_worker_run_report', columns: ['project_id', 'bridge_id', 'card_id', 'started_at'], options: ['where' => "((run_key IS NULL) AND ((kind)::text = 'worker'::text))"])]
#[ORM\UniqueConstraint(name: 'uniq_bridge_worker_run_key', columns: ['project_id', 'bridge_id', 'run_key'])]
// One session holds one open interactive run on a card.
#[ORM\UniqueConstraint(name: 'uniq_bridge_worker_run_interactive_open', columns: ['project_id', 'card_id', 'session_id'], options: ['where' => "(((kind)::text = 'interactive'::text) AND ((state)::text = 'running'::text))"])]
class WorkerRun implements ProjectScopedSubject
{
    /** Mirrors the cap the bridge applies to a worker's output before it reports. */
    public const int MAX_OUTPUT_LENGTH = 4000;

    /** A work kind, or the name of an interactive run such as a skill name. */
    public const int MAX_WORK_KIND_LENGTH = 100;

    public const int MAX_RULE_ID_LENGTH = 100;

    public const int MAX_WORKER_POOL_LENGTH = 40;

    /** The name of a bridge worker pool, such as default. */
    public const string WORKER_POOL_PATTERN = '/^[a-z][a-z0-9-]{0,39}$/D';

    public const int MAX_FAILURE_REASON_LENGTH = 1000;

    /** The limit applies to the extra result fields once they are encoded as JSON. */
    public const int MAX_RESULT_FIELDS_BYTES = 4000;

    public const int MAX_RESUME_SKIPPED_LENGTH = 50;

    public const int MAX_RESULT_REASON_LENGTH = 40;

    /** The shape of a reason code. The server stores a code it does not know as other. */
    public const string RESULT_REASON_PATTERN = '/^[a-z][a-z0-9-]*$/D';

    public const int MAX_REQUESTED_MODEL_LENGTH = 100;

    public const int MAX_EXPERIMENT_NAME_LENGTH = 64;

    /** The name of an experiment or of one of its variants, bare for a route requirement. */
    public const string EXPERIMENT_NAME = '[a-z0-9][a-z0-9_-]{0,63}';

    public const string EXPERIMENT_NAME_PATTERN = '/^'.self::EXPERIMENT_NAME.'$/D';

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    /**
     * The card number, the work kind and the output as one searchable vector.
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

    /** Why the run ended the way it did. Null when the bridge sent no reason. */
    #[ORM\Column(name: 'result_reason', length: self::MAX_RESULT_REASON_LENGTH, nullable: true, enumType: WorkerRunReason::class)]
    public ?WorkerRunReason $resultReason = null;

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

    /** The pool the bridge ran the worker in, as its last report named it. Null from an older bridge. */
    #[ORM\Column(name: 'worker_pool', length: self::MAX_WORKER_POOL_LENGTH, nullable: true)]
    public ?string $workerPool = null;

    /** Where the WorkerRunUsage rows of the run come from. Null when an older bridge sent no usage. */
    #[ORM\Column(name: 'usage_source', length: 20, nullable: true, enumType: WorkerRunUsageSource::class)]
    public ?WorkerRunUsageSource $usageSource = null;

    /** The experiment of the rule that ran the worker. Null when the rule runs no experiment. */
    #[ORM\Column(name: 'experiment', length: self::MAX_EXPERIMENT_NAME_LENGTH, nullable: true)]
    public ?string $experiment = null;

    #[ORM\Column(name: 'variant', length: self::MAX_EXPERIMENT_NAME_LENGTH, nullable: true)]
    public ?string $variant = null;

    /** The model the variant asked for, which can differ from the models the usage reports. */
    #[ORM\Column(name: 'requested_model', length: self::MAX_REQUESTED_MODEL_LENGTH, nullable: true)]
    public ?string $requestedModel = null;

    /** The variant the card was pinned to before this run, when the rule no longer offered it. */
    #[ORM\Column(name: 'switched_from', length: self::MAX_EXPERIMENT_NAME_LENGTH, nullable: true)]
    public ?string $switchedFrom = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        /** The bridge that ran the work or launched the session. An opaque scalar: no table holds a bridge. */
        #[ORM\Column(name: 'bridge_id', type: UuidType::NAME, nullable: true)]
        public readonly ?Uuid $bridgeId,

        /** A scalar, never a foreign key, so a deleted card leaves its run history intact. */
        #[ORM\Column(name: 'card_id', type: UuidType::NAME)]
        public readonly Uuid $cardId,

        #[ORM\Column(name: 'card_number')]
        public readonly int $cardNumber,

        /** The kind of the work request, or the name of an interactive run. Null for a run of an old bridge rule. */
        #[ORM\Column(name: 'work_kind', length: self::MAX_WORK_KIND_LENGTH, nullable: true)]
        public readonly ?string $workKind,

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

        // Rows the previous image writes are worker runs.
        #[ORM\Column(name: 'kind', length: 20, enumType: WorkerRunKind::class, options: ['default' => WorkerRunKind::Worker->value])]
        public readonly WorkerRunKind $kind = WorkerRunKind::Worker,

        /** A scalar, never a foreign key, so the run outlives its work request. */
        #[ORM\Column(name: 'work_request_id', type: UuidType::NAME, nullable: true)]
        public readonly ?Uuid $workRequestId = null,

        /** The id of the template rule that opened the work request. */
        #[ORM\Column(name: 'rule_id', length: self::MAX_RULE_ID_LENGTH, nullable: true)]
        public readonly ?string $ruleId = null,
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

    /** A stop carries no exit code. A null output keeps the output the run holds. */
    public function recordStop(\DateTimeImmutable $endedAt, ?string $output): void
    {
        $this->state = WorkerRunState::Stopped;
        $this->endedAt = $endedAt;
        if (null !== $output) {
            $this->output = $output;
        }
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
        ?WorkerRunReason $resultReason = null,
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
        $this->resultReason = $resultReason;
        $this->resultFields = $resultFields;
        $this->resumeSkipped = $resumeSkipped;
    }

    #[\Override]
    public function scopedProject(): Project
    {
        return $this->project;
    }

    #[\Override]
    public function scopedSubjectType(): string
    {
        return 'worker_run';
    }
}
