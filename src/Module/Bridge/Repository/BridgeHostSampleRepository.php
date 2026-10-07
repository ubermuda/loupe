<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Bridge\Entity\BridgeHostSample;
use App\Module\Bridge\ValueObject\BridgeHostSampleReport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<BridgeHostSample>
 *
 * @phpstan-type SampleRow array{bridge_id: string, sampled_at: string, cpu_pct: string, mem_used: int|string, mem_total: int|string, swap_used: int|string, battery_pct: float|string|null, on_ac: bool|null}
 */
class BridgeHostSampleRepository extends ServiceEntityRepository
{
    private const string INSERT_SQL = <<<'SQL'
        INSERT INTO bridge_host_samples (
            id, owner_id, bridge_id, sampled_at, cpu_pct, mem_used, mem_total, swap_used, battery_pct, on_ac
        )
        SELECT
            s.id, :owner, :bridge, s.sampled_at, s.cpu_pct, s.mem_used, s.mem_total, s.swap_used, s.battery_pct, s.on_ac
        FROM jsonb_to_recordset(CAST(:samples AS jsonb)) AS s(
            id uuid, sampled_at timestamp, cpu_pct json, mem_used bigint, mem_total bigint, swap_used bigint,
            battery_pct double precision, on_ac boolean
        )
        ON CONFLICT (owner_id, bridge_id, sampled_at) DO NOTHING
        SQL;

    private const string COLUMNS = 'bridge_id, sampled_at, cpu_pct, mem_used, mem_total, swap_used, battery_pct, on_ac';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BridgeHostSample::class);
    }

    /**
     * Inserts the samples in one statement. A sample whose time the bridge
     * already holds, or that the batch repeats, is skipped. The bridge row
     * must exist, because the key points at it.
     *
     * @param list<BridgeHostSampleReport> $samples
     *
     * @return int the count of rows inserted
     */
    public function insertNew(Uuid $ownerId, Uuid $bridgeId, array $samples): int
    {
        if ([] === $samples) {
            return 0;
        }

        $rows = array_map(static fn (BridgeHostSampleReport $sample): array => [
            'id' => Uuid::v7()->toRfc4122(),
            'sampled_at' => $sample->sampledAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'cpu_pct' => $sample->cpuPct,
            'mem_used' => $sample->memUsed,
            'mem_total' => $sample->memTotal,
            'swap_used' => $sample->swapUsed,
            'battery_pct' => $sample->batteryPct,
            'on_ac' => $sample->onAc,
        ], $samples);

        return (int) $this->getEntityManager()->getConnection()->executeStatement(self::INSERT_SQL, [
            'owner' => $ownerId->toRfc4122(),
            'bridge' => $bridgeId->toRfc4122(),
            'samples' => json_encode($rows, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION),
        ]);
    }

    public function deleteSampledBefore(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM bridge_host_samples WHERE sampled_at < :cutoff',
            ['cutoff' => $cutoff->setTimezone(new \DateTimeZone('UTC'))],
            ['cutoff' => Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * Every sample of every bridge of the owner, one row at a time, for the
     * account data export.
     *
     * @return iterable<array{bridgeId: string, sample: BridgeHostSampleReport}>
     */
    public function iterateByOwner(Uuid $ownerId): iterable
    {
        $rows = $this->getEntityManager()->getConnection()->iterateAssociative(
            'SELECT '.self::COLUMNS.' FROM bridge_host_samples WHERE owner_id = :owner ORDER BY bridge_id, sampled_at',
            ['owner' => $ownerId->toRfc4122()],
        );

        /** @var SampleRow $row */
        foreach ($rows as $row) {
            yield ['bridgeId' => $row['bridge_id'], 'sample' => self::report($row)];
        }
    }

    /**
     * The samples of one bridge from $from to $to, both inclusive, oldest first.
     *
     * @return list<BridgeHostSampleReport>
     */
    public function findForBridgeBetween(Uuid $ownerId, Uuid $bridgeId, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit): array
    {
        /** @var list<SampleRow> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM bridge_host_samples
                WHERE owner_id = :owner AND bridge_id = :bridge AND sampled_at BETWEEN :from AND :to
                ORDER BY sampled_at
                LIMIT :limit',
            [
                'owner' => $ownerId->toRfc4122(),
                'bridge' => $bridgeId->toRfc4122(),
                'from' => $from->setTimezone(new \DateTimeZone('UTC')),
                'to' => $to->setTimezone(new \DateTimeZone('UTC')),
                'limit' => $limit,
            ],
            ['from' => Types::DATETIME_IMMUTABLE, 'to' => Types::DATETIME_IMMUTABLE, 'limit' => Types::INTEGER],
        );

        return array_map(self::report(...), $rows);
    }

    /** @param SampleRow $row */
    private static function report(array $row): BridgeHostSampleReport
    {
        /** @var list<int|float> $cpuPct */
        $cpuPct = json_decode($row['cpu_pct'], true, flags: \JSON_THROW_ON_ERROR);

        return new BridgeHostSampleReport(
            sampledAt: new \DateTimeImmutable($row['sampled_at'], new \DateTimeZone('UTC')),
            cpuPct: array_map(static fn (int|float $pct): float => (float) $pct, $cpuPct),
            memUsed: (int) $row['mem_used'],
            memTotal: (int) $row['mem_total'],
            swapUsed: (int) $row['swap_used'],
            batteryPct: null === $row['battery_pct'] ? null : (float) $row['battery_pct'],
            onAc: $row['on_ac'],
        );
    }
}
