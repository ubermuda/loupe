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
    /** Declared on every LIKE rather than left to the platform default, which is not portable. */
    private const string LIKE_ESCAPE = '!';

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
            $conditions[] = "LOWER(a.actorLabel) LIKE :actorLabel ESCAPE '".self::LIKE_ESCAPE."'";
            $parameters['actorLabel'] = '%'.self::escapeLike(mb_strtolower($actorLabel)).'%';
        }

        if (null !== $operationPrefix) {
            $conditions[] = "a.operation LIKE :operationPrefix ESCAPE '".self::LIKE_ESCAPE."'";
            $parameters['operationPrefix'] = self::escapeLike($operationPrefix).'%';
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

    /**
     * Neutralises the wildcards in a user-typed fragment so it matches as the
     * literal text it was typed as. The escape character goes first, or the one
     * this adds would be escaped again by the passes after it.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE.self::LIKE_ESCAPE, self::LIKE_ESCAPE.'%', self::LIKE_ESCAPE.'_'],
            $value,
        );
    }
}
