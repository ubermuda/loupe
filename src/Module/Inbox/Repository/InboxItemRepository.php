<?php

declare(strict_types=1);

namespace App\Module\Inbox\Repository;

use App\Module\Account\Entity\User;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InboxItem>
 */
class InboxItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InboxItem::class);
    }

    /** Read-then-write: a caller holds a lock on the project, or two items can take one number. */
    public function nextNumber(Project $project): int
    {
        $highest = $this->createQueryBuilder('i')
            ->select('MAX(i.number)')
            ->andWhere('i.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $highest ? 1 : ((int) $highest) + 1;
    }

    /**
     * Every item in the projects the user owns, with its card and document links.
     *
     * @return list<InboxItem>
     */
    public function findByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('i')
            ->join('i.project', 'p')
            ->leftJoin('i.cards', 'c')
            ->addSelect('c')
            ->leftJoin('i.documents', 'd')
            ->addSelect('d')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('i.createdAt', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult());
    }
}
