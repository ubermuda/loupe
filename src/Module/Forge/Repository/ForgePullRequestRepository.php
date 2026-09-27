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

    public function deleteByKey(Uuid $projectId, string $forge, string $repository, int $number): void
    {
        $this->createQueryBuilder('pr')
            ->delete()
            ->andWhere('pr.project = :project')
            ->andWhere('pr.forge = :forge')
            ->andWhere('pr.repository = :repository')
            ->andWhere('pr.number = :number')
            ->setParameter('project', $projectId, UuidType::NAME)
            ->setParameter('forge', $forge)
            ->setParameter('repository', mb_strtolower($repository))
            ->setParameter('number', $number)
            ->getQuery()
            ->execute();
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
