<?php

declare(strict_types=1);

namespace App\Module\Forge\Repository;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<ForgePullRequest> */
final class ForgePullRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForgePullRequest::class);
    }

    /**
     * Creates the row unless it exists, and answers its id either way. The insert
     * runs on the caller's connection, so a concurrent insert of the same key
     * neither fails nor aborts the caller's transaction.
     */
    public function insertIfMissing(Uuid $projectId, string $forge, string $repository, int $number): Uuid
    {
        $key = [
            'project' => $projectId->toRfc4122(),
            'forge' => $forge,
            'repository' => mb_strtolower($repository),
            'number' => $number,
        ];
        $connection = $this->getEntityManager()->getConnection();
        $connection->executeStatement(
            "INSERT INTO forge_pull_requests (id, project_id, forge, repository, number, state, draft, checks, failed_checks, mergeability, review, ready_to_merge, refresh_attempts)
            VALUES (:id, :project, :forge, :repository, :number, 'open', false, 'pending', '[]', 'unknown', 'none', false, 0)
            ON CONFLICT (project_id, forge, repository, number) DO NOTHING",
            ['id' => Uuid::v7()->toRfc4122(), ...$key],
        );

        $id = $connection->fetchOne(
            'SELECT id FROM forge_pull_requests WHERE project_id = :project AND forge = :forge AND repository = :repository AND number = :number',
            $key,
        );

        return Uuid::fromString(\is_string($id) ? $id : throw new \LogicException('The row exists after the insert.'));
    }

    /**
     * Skips a row that a read holds, so a card write under the project lock never waits on it.
     * The row stays tracked, and the sweep reads it until the pull request closes.
     */
    public function deleteByKey(Uuid $projectId, string $forge, string $repository, int $number): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM forge_pull_requests WHERE id IN (
                SELECT id FROM forge_pull_requests
                WHERE project_id = :project AND forge = :forge AND repository = :repository AND number = :number
                FOR UPDATE SKIP LOCKED
            )',
            ['project' => $projectId, 'forge' => $forge, 'repository' => mb_strtolower($repository), 'number' => $number],
            ['project' => UuidType::NAME],
        );
    }

    /**
     * The rows of the keys in one project, read in one query with no lock. The
     * query over-fetches across the three lists, so the rows are matched to the
     * keys after the read.
     *
     * @param list<array{forge: string, repository: string, number: int}> $keys
     *
     * @return list<ForgePullRequest>
     */
    public function findByKeys(Uuid $projectId, array $keys): array
    {
        if ([] === $keys) {
            return [];
        }

        $wanted = [];
        foreach ($keys as $key) {
            $wanted[self::key($key['forge'], mb_strtolower($key['repository']), $key['number'])] = true;
        }

        /** @var list<ForgePullRequest> $rows */
        $rows = $this->createQueryBuilder('pr')
            ->andWhere('pr.project = :project')
            ->andWhere('pr.forge IN (:forges)')
            ->andWhere('pr.repository IN (:repositories)')
            ->andWhere('pr.number IN (:numbers)')
            ->setParameter('project', $projectId, UuidType::NAME)
            ->setParameter('forges', array_values(array_unique(array_column($keys, 'forge'))))
            ->setParameter('repositories', array_values(array_unique(array_map(mb_strtolower(...), array_column($keys, 'repository')))))
            ->setParameter('numbers', array_values(array_unique(array_column($keys, 'number'))))
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $rows,
            static fn (ForgePullRequest $row): bool => isset($wanted[self::key($row->forge, $row->repository, $row->number)]),
        ));
    }

    /**
     * The rows of one repository in one project, narrowed by at most one of a
     * number, a head commit or a base branch, and by the open state on request.
     *
     * @param array{number?: int, headSha?: string, baseBranch?: string} $match
     *
     * @return list<Uuid>
     */
    public function findIds(Uuid $projectId, string $forge, string $repository, array $match, bool $openOnly): array
    {
        $query = $this->createQueryBuilder('pr')
            ->select('pr.id')
            ->andWhere('pr.project = :project')
            ->andWhere('pr.forge = :forge')
            ->andWhere('pr.repository = :repository')
            ->setParameter('project', $projectId, UuidType::NAME)
            ->setParameter('forge', $forge)
            ->setParameter('repository', mb_strtolower($repository));
        if ($openOnly) {
            $query->andWhere('pr.state = :open')->setParameter('open', PullRequestState::Open->value);
        }
        foreach ($match as $field => $value) {
            $query->andWhere(\sprintf('pr.%1$s = :%1$s', $field))->setParameter($field, $value);
        }

        return $this->toUuids($query->getQuery()->getSingleColumnResult());
    }

    /**
     * The open rows nobody read since $staleBefore, in id order after $afterId.
     *
     * @return list<Uuid>
     */
    public function findIdsOfStaleOpen(\DateTimeImmutable $staleBefore, ?Uuid $afterId, int $limit): array
    {
        $query = $this->createQueryBuilder('pr')
            ->select('pr.id')
            ->andWhere('pr.state = :open')
            ->andWhere('pr.refreshedAt IS NULL OR pr.refreshedAt < :staleBefore')
            ->setParameter('open', PullRequestState::Open->value)
            ->setParameter('staleBefore', $staleBefore, Types::DATETIME_IMMUTABLE)
            ->orderBy('pr.id', 'ASC')
            ->setMaxResults($limit);
        if (null !== $afterId) {
            $query->andWhere('pr.id > :afterId')->setParameter('afterId', $afterId, UuidType::NAME);
        }

        return $this->toUuids($query->getQuery()->getSingleColumnResult());
    }

    /** Re-reads a row this entity manager already holds, so the lock never guards stale fields. */
    public function findForUpdate(Uuid $id): ?ForgePullRequest
    {
        return $this->createQueryBuilder('pr')
            ->andWhere('pr.id = :id')
            ->setParameter('id', $id, UuidType::NAME)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    /**
     * Points every row of one repository in one project at its new path, and
     * answers how many moved. A row whose pull request already has a row under
     * the new path is dropped, because the two rows describe one pull request.
     */
    public function repoint(Uuid $projectId, string $forge, string $from, string $to): int
    {
        // Past this guard the delete below would match every row of the repository.
        if (mb_strtolower($from) === mb_strtolower($to)) {
            return 0;
        }

        $parameters = [
            'project' => $projectId->toRfc4122(),
            'forge' => $forge,
            'from' => mb_strtolower($from),
            'to' => mb_strtolower($to),
        ];
        $connection = $this->getEntityManager()->getConnection();
        $connection->executeStatement(
            'DELETE FROM forge_pull_requests old WHERE old.project_id = :project AND old.forge = :forge AND old.repository = :from
            AND EXISTS (SELECT 1 FROM forge_pull_requests new WHERE new.project_id = old.project_id AND new.forge = old.forge AND new.repository = :to AND new.number = old.number)',
            $parameters,
        );

        return (int) $connection->executeStatement(
            'UPDATE forge_pull_requests SET repository = :to WHERE project_id = :project AND forge = :forge AND repository = :from',
            $parameters,
        );
    }

    /**
     * The state of each row as the database holds it now. A scalar read, so
     * the identity map cannot answer with a row loaded before a lock.
     * $forUpdate locks the rows, in id order, until the transaction ends.
     *
     * @param list<array{forge: string, repository: string, number: int}> $keys
     *
     * @return array<string, PullRequestState> keyed by stateKey()
     */
    public function findCurrentStatesByKeys(Uuid $projectId, array $keys, bool $forUpdate = false): array
    {
        if ([] === $keys) {
            return [];
        }

        $query = $this->createQueryBuilder('pr')
            ->select('pr.forge', 'pr.repository', 'pr.number', 'pr.state')
            ->andWhere('pr.project = :project')
            ->andWhere('pr.forge IN (:forges)')
            ->andWhere('pr.repository IN (:repositories)')
            ->andWhere('pr.number IN (:numbers)')
            ->setParameter('project', $projectId, UuidType::NAME)
            ->setParameter('forges', array_values(array_unique(array_column($keys, 'forge'))))
            ->setParameter('repositories', array_values(array_unique(array_map(mb_strtolower(...), array_column($keys, 'repository')))))
            ->setParameter('numbers', array_values(array_unique(array_column($keys, 'number'))))
            ->orderBy('pr.id', 'ASC')
            ->getQuery();
        if ($forUpdate) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }
        $rows = $query->getArrayResult();

        $states = [];
        foreach ($rows as $row) {
            $state = $row['state'];
            $states[self::stateKey((string) $row['forge'], (string) $row['repository'], (int) $row['number'])] = $state instanceof PullRequestState ? $state : PullRequestState::from((string) $state);
        }

        return $states;
    }

    public static function stateKey(string $forge, string $repository, int $number): string
    {
        return $forge.' '.mb_strtolower($repository).'#'.$number;
    }

    private static function key(string $forge, string $repository, int $number): string
    {
        return $forge.' '.$repository.'#'.$number;
    }

    /**
     * @param array<mixed> $ids
     *
     * @return list<Uuid>
     */
    private function toUuids(array $ids): array
    {
        return array_values(array_map(
            static fn (mixed $id): Uuid => $id instanceof Uuid ? $id : Uuid::fromString(\is_string($id) ? $id : throw new \LogicException('An id is a string.')),
            $ids,
        ));
    }
}
