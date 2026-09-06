<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardStatus;
use App\Module\Board\Entity\CardType;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Card>
 */
class CardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Card::class);
    }

    /**
     * Scoped to the project rather than looked up by id alone, so a caller
     * holding an id from another project reads nothing.
     */
    public function findOneByIdAndProject(Uuid $id, Project $project): ?Card
    {
        return $this->findOneBy(['id' => $id, 'project' => $project]);
    }

    /**
     * The cards of one (project, status, priority) group, in board order.
     *
     * @return list<Card>
     */
    public function findGroup(Project $project, CardStatus $status, CardPriority $priority): array
    {
        return $this->findBy(
            ['project' => $project, 'status' => $status, 'priority' => $priority],
            ['position' => 'ASC', 'createdAt' => 'ASC'],
        );
    }

    /** The rank a card appended to the end of that group takes. */
    public function nextPosition(Project $project, CardStatus $status, CardPriority $priority): int
    {
        $highest = $this->createQueryBuilder('c')
            ->select('MAX(c.position)')
            ->andWhere('c.project = :project')
            ->andWhere('c.status = :status')
            ->andWhere('c.priority = :priority')
            ->setParameter('project', $project)
            ->setParameter('status', $status)
            ->setParameter('priority', $priority)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $highest ? 0 : ((int) $highest) + 1;
    }

    /**
     * The board's read query, one column at a time.
     *
     * A column is read on its own even when the caller asks for the whole
     * board, because Done sorts by completion while every other column sorts by
     * priority then position. One query with both orderings in it would have to
     * rank Done rows by a priority they no longer use. The cost is up to four
     * queries for an unfiltered read, each on the composite index.
     *
     * `CardStatus::cases()` is the column order, so the enum's declaration
     * order is what a whole-board read comes back in.
     *
     * @return list<Card>
     */
    public function findForBoard(Project $project, ?CardStatus $status = null, ?CardType $type = null, ?CardPriority $priority = null): array
    {
        $cards = [];
        foreach (null === $status ? CardStatus::cases() : [$status] as $column) {
            $cards = [...$cards, ...$this->findColumn($project, $column, $type, $priority)];
        }

        return $cards;
    }

    /** @return list<Card> */
    private function findColumn(Project $project, CardStatus $status, ?CardType $type, ?CardPriority $priority): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.project = :project')
            ->andWhere('c.status = :status')
            ->setParameter('project', $project)
            ->setParameter('status', $status);

        if (null !== $type) {
            $qb->andWhere('c.type = :type')->setParameter('type', $type);
        }
        if (null !== $priority) {
            $qb->andWhere('c.priority = :priority')->setParameter('priority', $priority);
        }

        if (CardStatus::Done === $status) {
            $qb->orderBy('c.completedAt', 'DESC');
        } else {
            $qb->orderBy('c.priority', 'ASC')->addOrderBy('c.position', 'ASC');
        }

        // Last, so two rows that tie on everything above still come back in a
        // stable order rather than in whatever order Postgres read them.
        $qb->addOrderBy('c.createdAt', 'ASC')->addOrderBy('c.id', 'ASC');

        return array_values($qb->getQuery()->getResult());
    }
}
