<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkRequestState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The claim, the renewal and the lapse sweep write with native SQL, so a
 * managed WorkRequest can be stale after them. Read it again through
 * findOneLocked, which refreshes.
 *
 * @extends ServiceEntityRepository<WorkRequest>
 */
class WorkRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkRequest::class);
    }

    /** Claims an open request for the bridge. Answers false when the request is not open, so a second claim loses. */
    public function claim(Uuid $id, Uuid $bridgeId, Uuid $token, \DateTimeImmutable $leaseUntil): bool
    {
        $sql = <<<'SQL'
            UPDATE work_requests
            SET state = :claimed, bridge_id = :bridge, claim_token = :token, lease_until = :leaseUntil, claims = claims + 1
            WHERE id = :id AND state = :open
            RETURNING id
            SQL;

        return false !== $this->getEntityManager()->getConnection()->executeQuery(
            $sql,
            [
                'claimed' => WorkRequestState::Claimed->value,
                'bridge' => $bridgeId->toRfc4122(),
                'token' => $token->toRfc4122(),
                'leaseUntil' => $leaseUntil,
                'id' => $id->toRfc4122(),
                'open' => WorkRequestState::Open->value,
            ],
            ['leaseUntil' => Types::DATETIME_IMMUTABLE],
        )->fetchOne();
    }

    /** Locked until the transaction ends, and read fresh even when the request is already managed. */
    public function findOneLocked(Uuid $id): ?WorkRequest
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.id = :id')
            ->setParameter('id', $id, UuidType::NAME)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    /**
     * Extends the lease of each claim the bridge still holds with its token.
     *
     * @param list<array{Uuid, Uuid}> $claims pairs of the request id and the claim token
     *
     * @return list<string> the RFC 4122 ids of the renewed requests
     */
    public function renewLeases(Uuid $bridgeId, array $claims, \DateTimeImmutable $leaseUntil): array
    {
        if ([] === $claims) {
            return [];
        }

        $sql = <<<'SQL'
            UPDATE work_requests
            SET lease_until = :leaseUntil
            FROM unnest(CAST(:ids AS uuid[]), CAST(:tokens AS uuid[])) AS held(id, token)
            WHERE work_requests.id = held.id
              AND work_requests.claim_token = held.token
              AND work_requests.bridge_id = :bridge
              AND work_requests.state = :claimed
            RETURNING work_requests.id
            SQL;

        $array = static fn (array $uuids): string => '{'.implode(',', array_map(static fn (Uuid $uuid): string => $uuid->toRfc4122(), $uuids)).'}';

        /** @var list<string> $ids */
        $ids = $this->getEntityManager()->getConnection()->executeQuery(
            $sql,
            [
                'leaseUntil' => $leaseUntil,
                'ids' => $array(array_column($claims, 0)),
                'tokens' => $array(array_column($claims, 1)),
                'bridge' => $bridgeId->toRfc4122(),
                'claimed' => WorkRequestState::Claimed->value,
            ],
            ['leaseUntil' => Types::DATETIME_IMMUTABLE],
        )->fetchFirstColumn();

        return $ids;
    }

    /**
     * The open requests of the projects that a bridge with these capabilities
     * can run, oldest first.
     *
     * @param list<Uuid>   $projectIds
     * @param list<string> $capabilities
     *
     * @return list<WorkRequest>
     */
    public function findOpenOffers(array $projectIds, array $capabilities, int $limit): array
    {
        if ([] === $projectIds) {
            return [];
        }

        $qb = $this->createQueryBuilder('w')
            ->andWhere('w.project IN (:projects)')
            ->andWhere('w.state = :open')
            ->setParameter('projects', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $projectIds))
            ->setParameter('open', WorkRequestState::Open->value)
            ->orderBy('w.createdAt', 'ASC')
            ->addOrderBy('w.id', 'ASC')
            ->setMaxResults($limit);
        if ([] === $capabilities) {
            $qb->andWhere('w.capability IS NULL');
        } else {
            $qb->andWhere('w.capability IS NULL OR w.capability IN (:capabilities)')
                ->setParameter('capabilities', $capabilities);
        }

        return array_values($qb->getQuery()->getResult());
    }

    /**
     * Opens again every claimed request whose lease lapsed. One statement, so
     * a renewal that commits first keeps its claim.
     *
     * @return list<WorkRequest> the reopened requests, read fresh
     */
    public function reopenLapsed(\DateTimeImmutable $now): array
    {
        $sql = <<<'SQL'
            UPDATE work_requests
            SET state = :open, bridge_id = NULL, claim_token = NULL, lease_until = NULL
            WHERE state = :claimed AND lease_until <= :now
            RETURNING id
            SQL;

        $ids = $this->getEntityManager()->getConnection()->executeQuery(
            $sql,
            ['open' => WorkRequestState::Open->value, 'claimed' => WorkRequestState::Claimed->value, 'now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        )->fetchFirstColumn();
        if ([] === $ids) {
            return [];
        }

        return array_values($this->createQueryBuilder('w')
            ->andWhere('w.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('w.createdAt', 'ASC')
            ->addOrderBy('w.id', 'ASC')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult());
    }

    /** Reads the state alone, like the unique index. */
    public function hasLive(Uuid $cardId, string $kind): bool
    {
        return null !== $this->createQueryBuilder('w')
            ->select('1')
            ->andWhere('w.cardId = :cardId')
            ->andWhere('w.kind = :kind')
            ->andWhere('w.state IN (:live)')
            ->setParameter('cardId', $cardId, UuidType::NAME)
            ->setParameter('kind', $kind)
            ->setParameter('live', [WorkRequestState::Open->value, WorkRequestState::Claimed->value])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
