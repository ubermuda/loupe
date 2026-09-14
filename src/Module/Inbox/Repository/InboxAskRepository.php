<?php

declare(strict_types=1);

namespace App\Module\Inbox\Repository;

use App\Module\Account\Entity\User;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InboxAsk>
 */
class InboxAskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InboxAsk::class);
    }

    /**
     * Every ask in the projects the user owns, with its item memberships.
     *
     * @return list<InboxAsk>
     */
    public function findByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('a')
            ->join('a.project', 'p')
            ->leftJoin('a.items', 'l')
            ->addSelect('l')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('a.createdAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * The open asks of a project, oldest first, with their items.
     *
     * @return list<InboxAsk>
     */
    public function findOpenForProject(Project $project): array
    {
        return array_values($this->withItems()
            ->andWhere('a.project = :project')
            ->andWhere('a.closedAt IS NULL')
            ->setParameter('project', $project)
            ->orderBy('a.createdAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->addOrderBy('l.addedAt', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * The open asks in every project the user owns, with their projects and items,
     * ordered by project name and then oldest ask first.
     *
     * @return list<InboxAsk>
     */
    public function findOpenByOwner(User $user): array
    {
        return array_values($this->withItems()
            ->join('a.project', 'p')
            ->addSelect('p')
            ->addSelect('LOWER(p.name) AS HIDDEN projectName')
            ->andWhere('p.owner = :user')
            ->andWhere('a.closedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('projectName', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->addOrderBy('a.createdAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->addOrderBy('l.addedAt', 'ASC')
            ->getQuery()
            ->getResult());
    }

    public function countClosedForProject(Project $project): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.project = :project')
            ->andWhere('a.closedAt IS NOT NULL')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * One page of the closed asks of a project, newest close first, with their items.
     *
     * The page is cut on ids first: a limit on the fetch-joined query would
     * count item rows, not asks.
     *
     * @return list<InboxAsk>
     */
    public function findClosedPageForProject(Project $project, int $offset, int $limit): array
    {
        $ids = array_column($this->createQueryBuilder('a')
            ->select('a.id')
            ->andWhere('a.project = :project')
            ->andWhere('a.closedAt IS NOT NULL')
            ->setParameter('project', $project)
            ->orderBy('a.closedAt', 'DESC')
            ->addOrderBy('a.id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult(), 'id');

        if ([] === $ids) {
            return [];
        }

        return array_values($this->withItems()
            ->andWhere('a.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('a.closedAt', 'DESC')
            ->addOrderBy('a.id', 'ASC')
            ->addOrderBy('l.addedAt', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * The ids of the given items that a closed ask holds, which makes an answer on them final.
     *
     * @param list<InboxItem> $items
     *
     * @return list<string>
     */
    public function findItemIdsHeldByClosedAsks(array $items): array
    {
        if ([] === $items) {
            return [];
        }

        /** @var list<array{id: mixed}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT IDENTITY(l.item) AS id')
            ->from(InboxAskItem::class, 'l')
            ->join('l.ask', 'a')
            ->andWhere('l.item IN (:items)')
            ->andWhere('a.closedAt IS NOT NULL')
            ->setParameter('items', $items)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => (string) $row['id'], $rows);
    }

    private function withItems(): QueryBuilder
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.items', 'l')
            ->addSelect('l')
            ->leftJoin('l.item', 'i')
            ->addSelect('i');
    }
}
