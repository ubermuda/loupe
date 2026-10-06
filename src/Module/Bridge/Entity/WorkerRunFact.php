<?php

declare(strict_types=1);

namespace App\Module\Bridge\Entity;

use App\Module\Bridge\Repository\WorkerRunFactRepository;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One row per worker run, with the sums of its usage, for the analytics. The
 * row outlives its run, so the run id is a scalar. WorkerRunFactWriter writes
 * the row with SQL, never through the ORM.
 */
#[ORM\Entity(repositoryClass: WorkerRunFactRepository::class)]
#[ORM\Index(name: 'idx_bridge_worker_run_facts_project_ended', columns: ['project_id', 'ended_at'])]
#[ORM\Index(name: 'idx_bridge_worker_run_facts_subject', columns: ['project_id', 'subject_type', 'subject_id'])]
#[ORM\Table(name: 'bridge_worker_run_facts')]
class WorkerRunFact
{
    public function __construct(
        #[ORM\Column(name: 'run_id', type: UuidType::NAME)]
        #[ORM\Id]
        public readonly Uuid $runId,

        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\Column(name: 'subject_type', length: 40)]
        public readonly string $subjectType,

        #[ORM\Column(name: 'subject_id', type: UuidType::NAME)]
        public readonly Uuid $subjectId,

        /** A display label. The subject identifies the card. */
        #[ORM\Column(name: 'card_number', nullable: true)]
        public readonly ?int $cardNumber,

        #[ORM\Column(name: 'kind', length: 20, enumType: WorkerRunKind::class)]
        public readonly WorkerRunKind $kind,

        #[ORM\Column(name: 'work_kind', length: WorkerRun::MAX_WORK_KIND_LENGTH, nullable: true)]
        public readonly ?string $workKind,

        #[ORM\Column(name: 'rule_id', length: WorkerRun::MAX_RULE_ID_LENGTH, nullable: true)]
        public readonly ?string $ruleId,

        #[ORM\Column(name: 'experiment', length: WorkerRun::MAX_EXPERIMENT_NAME_LENGTH, nullable: true)]
        public readonly ?string $experiment,

        #[ORM\Column(name: 'variant', length: WorkerRun::MAX_EXPERIMENT_NAME_LENGTH, nullable: true)]
        public readonly ?string $variant,

        /** The model of the usage row with the highest cost. */
        #[ORM\Column(name: 'model', length: WorkerRunUsage::MAX_MODEL_LENGTH, nullable: true)]
        public readonly ?string $model,

        #[ORM\Column(name: 'bridge_id', type: UuidType::NAME, nullable: true)]
        public readonly ?Uuid $bridgeId,

        /** The state of the run. */
        #[ORM\Column(name: 'outcome', length: 20, enumType: WorkerRunState::class)]
        public readonly WorkerRunState $outcome,

        #[ORM\Column(name: 'started_at', nullable: true)]
        public readonly ?\DateTimeImmutable $startedAt,

        #[ORM\Column(name: 'ended_at', nullable: true)]
        public readonly ?\DateTimeImmutable $endedAt,

        #[ORM\Column(name: 'received_at')]
        public readonly \DateTimeImmutable $receivedAt,

        #[ORM\Column(name: 'duration_ms', type: Types::BIGINT, nullable: true)]
        public readonly ?int $durationMs,

        /** Null when the run has no usage source, or when one usage row has no price. */
        #[ORM\Column(name: 'cost_micro_usd', type: Types::BIGINT, nullable: true)]
        public readonly ?int $costMicroUsd,

        #[ORM\Column(name: 'tokens_in', type: Types::BIGINT, nullable: true)]
        public readonly ?int $tokensIn,

        #[ORM\Column(name: 'tokens_out', type: Types::BIGINT, nullable: true)]
        public readonly ?int $tokensOut,

        #[ORM\Column(name: 'tokens_cache_read', type: Types::BIGINT, nullable: true)]
        public readonly ?int $tokensCacheRead,

        #[ORM\Column(name: 'tokens_cache_write', type: Types::BIGINT, nullable: true)]
        public readonly ?int $tokensCacheWrite,

        #[ORM\Column(name: 'usage_source', length: 20, nullable: true, enumType: WorkerRunUsageSource::class)]
        public readonly ?WorkerRunUsageSource $usageSource,
    ) {
    }
}
