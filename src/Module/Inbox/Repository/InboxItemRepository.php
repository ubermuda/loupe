<?php

declare(strict_types=1);

namespace App\Module\Inbox\Repository;

use App\Module\Account\Entity\User;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

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

    /** The item with this id inside this project, so a URL cannot reach another project's item. */
    public function findOneByIdAndProjectId(string $itemId, string $projectId): ?InboxItem
    {
        if (!Uuid::isValid($itemId) || !Uuid::isValid($projectId)) {
            return null;
        }

        return $this->createQueryBuilder('i')
            ->andWhere('i.id = :itemId')
            ->andWhere('i.project = :projectId')
            ->setParameter('itemId', Uuid::fromString($itemId), UuidType::NAME)
            ->setParameter('projectId', Uuid::fromString($projectId), UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Open items that no open ask holds, such as a to-do left open in an ask
     * that already closed.
     *
     * @return list<InboxItem>
     */
    public function findOpenOutsideOpenAsks(Project $project): array
    {
        return array_values($this->createQueryBuilder('i')
            ->andWhere('i.project = :project')
            ->andWhere('i.state = :open')
            ->andWhere('NOT EXISTS (SELECT 1 FROM '.InboxAskItem::class.' l JOIN l.ask a WHERE l.item = i AND a.closedAt IS NULL)')
            ->setParameter('project', $project)
            ->setParameter('open', InboxItemState::Open)
            ->orderBy('i.number', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * The state and close time as stored now, whatever the loaded entity holds.
     *
     * @return array{InboxItemState, ?\DateTimeImmutable}
     */
    public function currentStateOf(InboxItem $item): array
    {
        /** @var array{state: InboxItemState, closedAt: ?\DateTimeImmutable} $row */
        $row = $this->createQueryBuilder('i')
            ->select('i.state, i.closedAt')
            ->andWhere('i.id = :id')
            ->setParameter('id', $item->id, UuidType::NAME)
            ->getQuery()
            ->getSingleResult();

        return [$row['state'], $row['closedAt']];
    }

    public function countOpenByProject(Project $project): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.project = :project')
            ->andWhere('i.state = :open')
            ->setParameter('project', $project)
            ->setParameter('open', InboxItemState::Open)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param list<Project> $projects
     *
     * @return array<string, int> keyed by project id; a project with no open item is absent
     */
    public function countOpenByProjects(array $projects): array
    {
        if ([] === $projects) {
            return [];
        }

        /** @var list<array{id: mixed, total: mixed}> $rows */
        $rows = $this->createQueryBuilder('i')
            ->select('IDENTITY(i.project) AS id, COUNT(i.id) AS total')
            ->andWhere('i.project IN (:projects)')
            ->andWhere('i.state = :open')
            ->setParameter('projects', $projects)
            ->setParameter('open', InboxItemState::Open)
            ->groupBy('i.project')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['id']] = (int) $row['total'];
        }

        return $counts;
    }
}
