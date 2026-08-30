<?php

declare(strict_types=1);

namespace App\Module\Audit\Repository;

use App\Module\Audit\AuditOutcome;
use App\Module\Audit\Entity\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @phpstan-type AuditLogRowData array{
 *     id: Uuid,
 *     operation: string,
 *     outcome: AuditOutcome,
 *     category: string,
 *     channel: string,
 *     occurredAt: \DateTimeImmutable,
 *     context: array<string, scalar|null>,
 *     actorLabel: ?string,
 *     subjectType: ?string,
 *     subjectId: ?string,
 * }
 *
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * One page of the trail as plain rows. Array hydration, not entities: the
     * actor is a lazy association, so a hydrated page would cost one query per
     * distinct actor the moment a reader renders the name.
     *
     * @return list<AuditLogRowData>
     */
    public function findPageForAdmin(
        ?string $actorLabel,
        ?string $operationPrefix,
        ?string $channel,
        ?\DateTimeImmutable $occurredFrom,
        ?\DateTimeImmutable $occurredBefore,
        string $direction,
        int $limit,
        int $offset,
    ): array {
        $builder = $this->createQueryBuilder('a')
            ->select(
                'a.id AS id',
                'a.operation AS operation',
                'a.outcome AS outcome',
                'a.category AS category',
                'a.channel AS channel',
                'a.occurredAt AS occurredAt',
                'a.context AS context',
                'a.actorLabel AS actorLabel',
                'a.subjectType AS subjectType',
                'a.subjectId AS subjectId',
            )
            ->orderBy('a.occurredAt', 'asc' === $direction ? 'ASC' : 'DESC')
            ->addOrderBy('a.id', 'asc' === $direction ? 'ASC' : 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        [$conditions, $parameters] = $this->adminCriteria($actorLabel, $operationPrefix, $channel, $occurredFrom, $occurredBefore);
        foreach ($conditions as $condition) {
            $builder->andWhere($condition);
        }
        foreach ($parameters as $name => $value) {
            $builder->setParameter($name, $value);
        }

        /** @var list<AuditLogRowData> $rows */
        $rows = $builder->getQuery()->getArrayResult();

        return $rows;
    }

    public function countForAdmin(
        ?string $actorLabel,
        ?string $operationPrefix,
        ?string $channel,
        ?\DateTimeImmutable $occurredFrom,
        ?\DateTimeImmutable $occurredBefore,
    ): int {
        $builder = $this->createQueryBuilder('a')->select('COUNT(a.id)');

        [$conditions, $parameters] = $this->adminCriteria($actorLabel, $operationPrefix, $channel, $occurredFrom, $occurredBefore);
        foreach ($conditions as $condition) {
            $builder->andWhere($condition);
        }
        foreach ($parameters as $name => $value) {
            $builder->setParameter($name, $value);
        }

        return (int) $builder->getQuery()->getSingleScalarResult();
    }

    /**
     * @return array{0: list<string>, 1: array<string, string|\DateTimeImmutable>}
     */
    private function adminCriteria(
        ?string $actorLabel,
        ?string $operationPrefix,
        ?string $channel,
        ?\DateTimeImmutable $occurredFrom,
        ?\DateTimeImmutable $occurredBefore,
    ): array {
        $conditions = [];
        $parameters = [];

        if (null !== $actorLabel) {
            $conditions[] = 'LOWER(a.actorLabel) LIKE :actorLabel';
            $parameters['actorLabel'] = '%'.mb_strtolower($actorLabel).'%';
        }

        if (null !== $operationPrefix) {
            $conditions[] = 'a.operation LIKE :operationPrefix';
            $parameters['operationPrefix'] = $operationPrefix.'%';
        }

        if (null !== $channel) {
            $conditions[] = 'a.channel = :channel';
            $parameters['channel'] = $channel;
        }

        if (null !== $occurredFrom) {
            $conditions[] = 'a.occurredAt >= :occurredFrom';
            $parameters['occurredFrom'] = $occurredFrom;
        }

        if (null !== $occurredBefore) {
            $conditions[] = 'a.occurredAt < :occurredBefore';
            $parameters['occurredBefore'] = $occurredBefore;
        }

        return [$conditions, $parameters];
    }
}
