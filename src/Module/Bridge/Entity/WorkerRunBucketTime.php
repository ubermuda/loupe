<?php

declare(strict_types=1);

namespace App\Module\Bridge\Entity;

use App\Module\Bridge\Repository\WorkerRunBucketTimeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The time a run spent in one bucket of the project rules. The repository
 * writes the row with SQL, so a repeated computation changes nothing.
 */
#[ORM\Entity(repositoryClass: WorkerRunBucketTimeRepository::class)]
#[ORM\Index(name: 'idx_bridge_worker_run_bucket_time_bucket', columns: ['bucket'])]
#[ORM\Table(name: 'bridge_worker_run_bucket_times')]
#[ORM\UniqueConstraint(name: 'uniq_bridge_worker_run_bucket_time', columns: ['run_id', 'bucket'])]
class WorkerRunBucketTime
{
    public function __construct(
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        #[ORM\Id]
        public readonly Uuid $id,

        // The retention sweep deletes runs with DQL, so only the database can remove the row.
        #[ORM\JoinColumn(name: 'run_id', nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: WorkerRun::class)]
        public readonly WorkerRun $run,

        #[ORM\Column(name: 'bucket', length: 64)]
        public readonly string $bucket,

        #[ORM\Column(name: 'ms', type: Types::BIGINT)]
        public readonly int $ms,
    ) {
    }
}
