<?php

declare(strict_types=1);

namespace App\Module\Bridge\Entity;

use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunReason;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Module\Bridge\ValueObject\WorkSubject;
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
// The board and the workflow engine read the runs of one card.
#[ORM\Index(name: 'idx_bridge_worker_runs_subject', columns: ['project_id', 'subject_type', 'subject_id'])]
#[ORM\Table(name: 'bridge_worker_runs')]
// Guards the worker runs with no run key, which only the removed finished-run report wrote.
// The predicate is written the way Postgres stores it, so migrate-diff stays quiet.
#[ORM\UniqueConstraint(name: 'uniq_bridge_worker_run_report', columns: ['project_id', 'bridge_id', 'subject_type', 'subject_id', 'started_at'], options: ['where' => "((run_key IS NULL) AND ((kind)::text = 'worker'::text))"])]
#[ORM\UniqueConstraint(name: 'uniq_bridge_worker_run_key', columns: ['project_id', 'bridge_id', 'run_key'])]
// One session holds one open interactive run on a subject.
#[ORM\UniqueConstraint(name: 'uniq_bridge_worker_run_interactive_open', columns: ['project_id', 'subject_type', 'subject_id', 'session_id'], options: ['where' => "(((kind)::text = 'interactive'::text) AND ((state)::text = 'running'::text))"])]
class WorkerRun implements ProjectScopedSubject
{
    /** A process killed by a signal reports a negative code, so the range is symmetric around the 0 to 255 of a normal exit. */
    public const int MIN_EXIT_CODE = -255;

    public const int MAX_EXIT_CODE = 255;

    /** The card number column is a 32-bit integer, and Postgres refuses more at the flush. */
    public const int MAX_CARD_NUMBER = 2147483647;

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

    public const int MAX_HARNESS_LENGTH = 40;

    /** The name of a harness, such as claude-code or codex. */
    public const string HARNESS_PATTERN = '/^[a-z][a-z0-9-]{0,39}$/D';

    public const int MAX_ACCOUNT_LENGTH = 64;

    /** The short name of a harness account. Never a path or a key. */
    public const string ACCOUNT_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D';

    public const int MAX_MODEL_LENGTH = 100;

    /** A model name has no control characters. */
    public const string MODEL_PATTERN = '/^[^\p{C}]+$/uD';

    public const int MAX_HARNESS_SESSION_ID_LENGTH = 100;

    public const string HARNESS_SESSION_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/D';

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

    /** The harness that ran the run, such as claude-code or codex. Null from an older bridge. */
    #[ORM\Column(name: 'harness', length: self::MAX_HARNESS_LENGTH, nullable: true)]
    public ?string $harness = null;

    /** The named harness account the run used. Null from an older bridge. */
    #[ORM\Column(name: 'account', length: self::MAX_ACCOUNT_LENGTH, nullable: true)]
    public ?string $account = null;

    /** The model the harness ran. Null until the bridge names it. */
    #[ORM\Column(name: 'model', length: self::MAX_MODEL_LENGTH, nullable: true)]
    public ?string $model = null;

    /** The harness's own id for the session, such as a Codex thread id. Loupe's own id stays in $sessionId. */
    #[ORM\Column(name: 'harness_session_id', length: self::MAX_HARNESS_SESSION_ID_LENGTH, nullable: true)]
    public ?string $harnessSessionId = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        /** The bridge that ran the work or launched the session. An opaque scalar: no table holds a bridge. */
        #[ORM\Column(name: 'bridge_id', type: UuidType::NAME, nullable: true)]
        public readonly ?Uuid $bridgeId,

        #[ORM\Column(name: 'subject_type', length: WorkSubject::MAX_TYPE_LENGTH)]
        public readonly string $subjectType,

        /** A scalar, never a foreign key, so a deleted card leaves its run history intact. For a card subject, the card id. */
        #[ORM\Column(name: 'subject_id', type: UuidType::NAME)]
        public readonly Uuid $subjectId,

        /** The card number a person sees, for a card subject. A label, never an id. */
        #[ORM\Column(name: 'card_number', nullable: true)]
        public readonly ?int $cardNumber,

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

    public function subject(): WorkSubject
    {
        return new WorkSubject($this->subjectType, $this->subjectId);
    }

    /** The card the run is about, or null when its subject is no card. */
    public function cardId(): ?Uuid
    {
        return WorkSubject::CARD === $this->subjectType ? $this->subjectId : null;
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

    /** A null keeps the stored value, because a value can arrive in a later report. Returns whether a field changed. */
    public function recordHarness(?string $harness, ?string $account, ?string $model, ?string $harnessSessionId): bool
    {
        $before = [$this->harness, $this->account, $this->model, $this->harnessSessionId];
        $this->harness = $harness ?? $this->harness;
        $this->account = $account ?? $this->account;
        $this->model = $model ?? $this->model;
        $this->harnessSessionId = $harnessSessionId ?? $this->harnessSessionId;

        return $before !== [$this->harness, $this->account, $this->model, $this->harnessSessionId];
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
