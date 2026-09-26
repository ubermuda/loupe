<?php

declare(strict_types=1);

namespace App\Module\Bridge\Entity;

use App\Module\Bridge\Repository\WorkerRunUsageRepository;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The tokens one model spent in one run. A row outlives its run, so the
 * retention sweep keeps the spend of a card, and it carries the project, the
 * card, the rule and the usage source of the run for that reason.
 */
#[ORM\Entity(repositoryClass: WorkerRunUsageRepository::class)]
// The spend of one card.
#[ORM\Index(name: 'idx_bridge_worker_run_usage_card', columns: ['project_id', 'card_id'])]
#[ORM\Table(name: 'bridge_worker_run_usage')]
#[ORM\UniqueConstraint(name: 'uniq_bridge_worker_run_usage_model', columns: ['run_id', 'model'])]
class WorkerRunUsage
{
    public const int MAX_MODEL_LENGTH = 100;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        // The retention sweep deletes runs with DQL, so only the database can unlink the row.
        #[ORM\JoinColumn(name: 'run_id', nullable: true, onDelete: 'SET NULL')]
        #[ORM\ManyToOne(targetEntity: WorkerRun::class)]
        public ?WorkerRun $run,

        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        /** A scalar, never a foreign key, like the card of the run. */
        #[ORM\Column(name: 'card_id', type: UuidType::NAME)]
        public readonly Uuid $cardId,

        #[ORM\Column(name: 'rule_name', length: WorkerRun::MAX_RULE_NAME_LENGTH)]
        public readonly string $ruleName,

        #[ORM\Column(name: 'model', length: self::MAX_MODEL_LENGTH)]
        public readonly string $model,

        #[ORM\Column(name: 'source', length: 20, enumType: WorkerRunUsageSource::class)]
        public readonly WorkerRunUsageSource $source,

        #[ORM\Column(name: 'input_tokens', type: Types::BIGINT)]
        public readonly int $inputTokens,

        #[ORM\Column(name: 'output_tokens', type: Types::BIGINT)]
        public readonly int $outputTokens,

        #[ORM\Column(name: 'cache_read_tokens', type: Types::BIGINT)]
        public readonly int $cacheReadTokens,

        #[ORM\Column(name: 'cache_write_tokens', type: Types::BIGINT)]
        public readonly int $cacheWriteTokens,

        /** Null when the bridge estimated a model it knows no price for. */
        #[ORM\Column(name: 'cost_usd', type: Types::DECIMAL, precision: 12, scale: 6, nullable: true)]
        public readonly ?string $costUsd,
    ) {
    }
}
