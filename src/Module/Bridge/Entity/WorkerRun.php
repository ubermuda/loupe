<?php

declare(strict_types=1);

namespace App\Module\Bridge\Entity;

use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use MartinGeorgiev\Doctrine\DBAL\Type as PostgresType;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One run of one CLI bridge worker, as the bridge reported it. A row is written
 * once and never updated.
 */
#[ORM\Entity(repositoryClass: WorkerRunRepository::class)]
// The page reads one project newest first, and the retention sweep deletes one
// project-independent range of the same column.
#[ORM\Index(name: 'idx_bridge_worker_runs_project_received', columns: ['project_id', 'received_at'])]
// No access method: DBAL's Postgres platform ignores index flags, and the
// migration creates it USING gin. flags: ['gin'] would make the comparator emit
// a DROP plus a plain CREATE INDEX, downgrading it to a B-tree that @@ never uses.
#[ORM\Index(name: 'idx_bridge_worker_runs_search_vector', columns: ['search_vector'])]
#[ORM\Table(name: 'bridge_worker_runs')]
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

        #[ORM\Column(name: 'started_at')]
        public readonly \DateTimeImmutable $startedAt,

        #[ORM\Column(name: 'ended_at')]
        public readonly \DateTimeImmutable $endedAt,

        /** Null means the process never ran, and $failureReason then says why. */
        #[ORM\Column(name: 'exit_code', nullable: true)]
        public readonly ?int $exitCode = null,

        #[ORM\Column(name: 'failure_reason', type: Types::TEXT, nullable: true)]
        public readonly ?string $failureReason = null,

        #[ORM\Column(name: 'output', type: Types::TEXT)]
        public readonly string $output = '',

        /**
         * The server clock. The gap to $endedAt is how long the report waited in
         * the bridge's retry queue, and it is the column retention sweeps.
         */
        #[ORM\Column(name: 'received_at')]
        public readonly \DateTimeImmutable $receivedAt = new \DateTimeImmutable(),
    ) {
    }
}
