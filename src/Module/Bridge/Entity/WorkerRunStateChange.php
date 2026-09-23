<?php

declare(strict_types=1);

namespace App\Module\Bridge\Entity;

use App\Module\Bridge\Repository\WorkerRunStateChangeRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One state a run reached, in the order the reports arrived. A report that
 * does not move the run forward still leaves a row here.
 */
#[ORM\Entity(repositoryClass: WorkerRunStateChangeRepository::class)]
#[ORM\Table(name: 'bridge_worker_run_states')]
class WorkerRunStateChange
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        // The cascade lives in the database, because the retention sweep and
        // the project and account purges delete runs with DQL or SQL.
        #[ORM\JoinColumn(name: 'run_id', nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: WorkerRun::class)]
        public readonly WorkerRun $run,

        #[ORM\Column(name: 'state', length: 20, enumType: WorkerRunState::class)]
        public readonly WorkerRunState $state,

        /** When the state happened: the bridge clock for a report, the server clock for an inference. */
        #[ORM\Column(name: 'at')]
        public readonly \DateTimeImmutable $at,

        #[ORM\Column(name: 'received_at')]
        public readonly \DateTimeImmutable $receivedAt = new \DateTimeImmutable(),
    ) {
    }
}
