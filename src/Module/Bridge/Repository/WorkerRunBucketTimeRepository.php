<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRunBucketTime;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<WorkerRunBucketTime>
 */
class WorkerRunBucketTimeRepository extends ServiceEntityRepository
{
    private const string UPSERT_SQL = <<<'SQL'
        INSERT INTO bridge_worker_run_bucket_times (id, run_id, bucket, ms)
        SELECT t.id, :run, t.bucket, t.ms
        FROM jsonb_to_recordset(CAST(:times AS jsonb)) AS t(id uuid, bucket text, ms bigint)
        ON CONFLICT (run_id, bucket) DO UPDATE SET ms = EXCLUDED.ms
        SQL;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkerRunBucketTime::class);
    }

    /**
     * Gives the run exactly these rows. The caller opens the transaction, so a
     * reader never sees the run with no rows in between.
     *
     * @param array<int|string, int> $times bucket name => milliseconds
     */
    public function replaceForRun(Uuid $runId, array $times): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $connection->executeStatement('DELETE FROM bridge_worker_run_bucket_times WHERE run_id = :run', ['run' => $runId->toRfc4122()]);
        if ([] === $times) {
            return;
        }

        $rows = [];
        foreach ($times as $bucket => $ms) {
            $rows[] = ['id' => Uuid::v7()->toRfc4122(), 'bucket' => (string) $bucket, 'ms' => $ms];
        }
        $connection->executeStatement(self::UPSERT_SQL, ['run' => $runId->toRfc4122(), 'times' => json_encode($rows, \JSON_THROW_ON_ERROR)]);
    }

    /**
     * The milliseconds each of the runs spent in the bucket. A run with rows
     * and none in this bucket gives 0. A run with no rows has no entry.
     *
     * @param list<Uuid> $runIds
     *
     * @return array<string, int> run id => milliseconds
     */
    public function findMillisecondsOfRuns(array $runIds, string $bucket): array
    {
        $times = [];
        foreach (array_chunk($runIds, 1000) as $chunk) {
            $rows = $this->getEntityManager()->getConnection()->fetchAllNumeric(
                'SELECT run_id, COALESCE(SUM(ms) FILTER (WHERE bucket = :bucket), 0) FROM bridge_worker_run_bucket_times WHERE run_id IN (:runs) GROUP BY run_id',
                ['bucket' => $bucket, 'runs' => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $chunk)],
                ['runs' => ArrayParameterType::STRING],
            );
            foreach ($rows as [$runId, $ms]) {
                $times[(string) $runId] = (int) $ms;
            }
        }

        return $times;
    }

    /**
     * The milliseconds each of the runs spent in each of its buckets. A run
     * with no rows has no entry.
     *
     * @param list<Uuid> $runIds
     *
     * @return array<string, array<string, int>> run id => bucket name => milliseconds
     */
    public function findAllMillisecondsOfRuns(array $runIds): array
    {
        $times = [];
        foreach (array_chunk($runIds, 1000) as $chunk) {
            $rows = $this->getEntityManager()->getConnection()->fetchAllNumeric(
                'SELECT run_id, bucket, ms FROM bridge_worker_run_bucket_times WHERE run_id IN (:runs)',
                ['runs' => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $chunk)],
                ['runs' => ArrayParameterType::STRING],
            );
            foreach ($rows as [$runId, $bucket, $ms]) {
                $times[(string) $runId][(string) $bucket] = (int) $ms;
            }
        }

        return $times;
    }

    /** @return list<string> the distinct bucket names with rows on the runs of the project, in order */
    public function findBucketNamesOfProject(Project $project): array
    {
        /** @var list<string> $names */
        $names = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT b.bucket FROM bridge_worker_run_bucket_times b JOIN bridge_worker_runs r ON r.id = b.run_id WHERE r.project_id = :project ORDER BY b.bucket',
            ['project' => (string) $project->id],
        );

        return $names;
    }

    /**
     * Every bucket row of every run on every project the user owns, for the
     * account data export.
     *
     * @return list<WorkerRunBucketTime>
     */
    public function findByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('b')
            ->join('b.run', 'r')
            ->join('r.project', 'p')
            ->addSelect('r', 'p')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('r.id', 'ASC')
            ->addOrderBy('b.bucket', 'ASC')
            ->getQuery()
            ->getResult());
    }
}
