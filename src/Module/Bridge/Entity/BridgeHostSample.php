<?php

declare(strict_types=1);

namespace App\Module\Bridge\Entity;

use App\Module\Bridge\Repository\BridgeHostSampleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One reading of the machine a bridge runs on, as its heartbeat reported it.
 * BridgeHostSampleRepository writes the row with SQL, so a repeated report of
 * a sample changes nothing.
 */
#[ORM\Entity(repositoryClass: BridgeHostSampleRepository::class)]
#[ORM\Index(name: 'idx_bridge_host_samples_bridge_sampled', columns: ['bridge_id', 'sampled_at'])]
#[ORM\Table(name: 'bridge_host_samples')]
#[ORM\UniqueConstraint(name: 'uniq_bridge_host_samples_owner_bridge_sampled', columns: ['owner_id', 'bridge_id', 'sampled_at'])]
class BridgeHostSample
{
    public function __construct(
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        #[ORM\Id]
        public readonly Uuid $id,

        // Account deletion removes bridges with SQL, so only the database can remove the row.
        #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'owner_id', nullable: false, onDelete: 'CASCADE')]
        #[ORM\JoinColumn(name: 'bridge_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Bridge::class)]
        public readonly Bridge $bridge,

        /** UTC. */
        #[ORM\Column(name: 'sampled_at')]
        public readonly \DateTimeImmutable $sampledAt,

        /**
         * The use of each core, in percent.
         *
         * @var list<float>
         */
        #[ORM\Column(name: 'cpu_pct', type: Types::JSON)]
        public readonly array $cpuPct,

        #[ORM\Column(name: 'mem_used', type: Types::BIGINT)]
        public readonly int $memUsed,

        #[ORM\Column(name: 'mem_total', type: Types::BIGINT)]
        public readonly int $memTotal,

        #[ORM\Column(name: 'swap_used', type: Types::BIGINT)]
        public readonly int $swapUsed,

        /** Null on a machine with no battery. */
        #[ORM\Column(name: 'battery_pct', nullable: true)]
        public readonly ?float $batteryPct,

        /** Null when the machine does not say. */
        #[ORM\Column(name: 'on_ac', nullable: true)]
        public readonly ?bool $onAc,
    ) {
    }
}
