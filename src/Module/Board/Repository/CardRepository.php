<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardStatus;
use App\Module\Board\Entity\CardType;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
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
     * Open cards of a project for the widget's picker, newest first, optionally
     * narrowed by a substring of the title.
     *
     * Done is excluded: the picker exists to attach feedback to work in flight,
     * and a finished card is the one answer a reviewer almost never wants.
     *
     * @return list<Card>
     */
    public function searchOpenForProject(Project $project, string $query, int $limit): array
    {
        $qb = $this->createQueryBuilder('c')
            ->where('c.project = :project')
            ->andWhere('c.status != :done')
            ->setParameter('project', $project)
            ->setParameter('done', CardStatus::Done)
            ->orderBy('c.number', 'DESC')
            ->setMaxResults($limit);

        if ('' !== $query) {
            // ESCAPE, because _ and % in a reviewer's search string would
            // otherwise be wildcards and quietly widen the match.
            $qb->andWhere('LOWER(c.title) LIKE :q ESCAPE \'!\'')
                ->setParameter('q', '%'.addcslashes(mb_strtolower($query), '%_!').'%');
        }

        /* @var list<Card> */
        return $qb->getQuery()->getResult();
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

    /**
     * Reads onto the card the two fields that say which group it is in.
     *
     * A board write locks the project row, and lock() leaves a card loaded
     * before that lock exactly as the request read it. EntityManager::refresh()
     * cannot stand in here, because it rehydrates every column and Doctrine
     * refuses to rewrite the readonly ones a Card carries. A card the database
     * no longer holds is left alone, which is what the flush already does with
     * it.
     */
    public function refreshGroup(Card $card): void
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT status, priority FROM board_cards WHERE id = :id',
            ['id' => (string) $card->id],
        );

        if (false === $row) {
            return;
        }

        $card->status = CardStatus::from((string) $row['status']);
        $card->priority = CardPriority::from((int) $row['priority']);
    }

    /** The number the project's next card takes. The first card of a project is 1. */
    public function nextNumber(Project $project): int
    {
        $highest = $this->createQueryBuilder('c')
            ->select('MAX(c.number)')
            ->andWhere('c.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $highest ? 1 : ((int) $highest) + 1;
    }

    /**
     * One card, scoped to its project.
     *
     * The project id is part of the lookup rather than checked afterwards, so a
     * URL that pairs one project with another project's card is a 404.
     */
    public function findOneByIdAndProjectId(string $cardId, string $projectId): ?Card
    {
        if (!Uuid::isValid($cardId) || !Uuid::isValid($projectId)) {
            return null;
        }

        return $this->createQueryBuilder('c')
            ->andWhere('c.id = :cardId')
            ->andWhere('c.project = :projectId')
            ->setParameter('cardId', Uuid::fromString($cardId), UuidType::NAME)
            ->setParameter('projectId', Uuid::fromString($projectId), UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The Done cards finished on or after the given moment, newest first.
     *
     * The board shows a recent slice of Done rather than all of it, so a column
     * that only ever grows does not become the page's whole height.
     *
     * @return list<Card>
     */
    public function findDoneSince(Project $project, \DateTimeImmutable $since): array
    {
        return array_values(
            $this->withPullRequests($this->doneQuery($project))
                ->andWhere('c.completedAt >= :since')
                ->setParameter('since', $since)
                ->getQuery()
                ->getResult(),
        );
    }

    /**
     * One page of the whole Done history, newest first.
     *
     * Through a Paginator, because the fetch-join multiplies the rows a LIMIT
     * counts: without it a page of 25 cards is cut short by their links.
     *
     * @return list<Card>
     */
    public function findDonePage(Project $project, int $offset, int $limit): array
    {
        $query = $this->withPullRequests($this->doneQuery($project))
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery();

        return array_values(iterator_to_array(new Paginator($query, fetchJoinCollection: true), false));
    }

    public function countDone(Project $project): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.project = :project')
            ->andWhere('c.status = :status')
            ->setParameter('project', $project)
            ->setParameter('status', CardStatus::Done)
            ->getQuery()
            ->getSingleScalarResult();
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

    /**
     * How many cards each project still has open, for the projects list.
     *
     * Open is every column except Done, so a board whose work is finished
     * counts zero rather than counting its history.
     *
     * @param list<Project> $projects
     *
     * @return array<string, int> project id => count, projects with none omitted
     */
    public function countOpenByProjects(array $projects): array
    {
        if ([] === $projects) {
            return [];
        }

        /** @var list<array{id: mixed, total: mixed}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.project) AS id, COUNT(c.id) AS total')
            ->andWhere('c.project IN (:projects)')
            ->andWhere('c.status != :done')
            ->setParameter('projects', $projects)
            ->setParameter('done', CardStatus::Done)
            ->groupBy('c.project')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Every card on every project the user owns, for the account data export.
     *
     * The pull request links are fetch-joined, because the export reads them on
     * every row and they are lazy otherwise.
     *
     * @return list<Card>
     */
    public function findByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('c')
            ->join('c.project', 'p')
            ->leftJoin('c.pullRequests', 'l')
            ->addSelect('l')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('c.createdAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->addOrderBy('l.addedAt', 'ASC')
            ->getQuery()
            ->getResult());
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

        // The tie-break runs with its column, not after both branches. Done sorts
        // newest first and completed_at holds whole seconds, so two cards finished
        // in the same second need a tie-break that also runs newest first. A shared
        // ascending one resolved them against the rule the column states.
        if (CardStatus::Done === $status) {
            $qb->orderBy('c.completedAt', 'DESC')
                ->addOrderBy('c.createdAt', 'DESC')
                ->addOrderBy('c.id', 'DESC');
        } else {
            $qb->orderBy('c.priority', 'ASC')
                ->addOrderBy('c.position', 'ASC')
                ->addOrderBy('c.createdAt', 'ASC')
                ->addOrderBy('c.id', 'ASC');
        }

        return array_values($this->withPullRequests($qb)->getQuery()->getResult());
    }

    /**
     * Hydrates each card's pull request links with the card.
     *
     * Every board and history row reads the link count, and the association is
     * lazy, so without this each card on the page costs its own query.
     *
     * Call it after the card ordering is set, never before: it appends the
     * association's own order, which a later orderBy() would drop. A fetch-join
     * ignores the #[ORM\OrderBy] on the property, so the DQL has to carry it.
     */
    private function withPullRequests(QueryBuilder $qb): QueryBuilder
    {
        return $qb
            ->leftJoin('c.pullRequests', 'pullRequest')
            ->addSelect('pullRequest')
            ->addOrderBy('pullRequest.addedAt', 'ASC');
    }

    /** Done in the order the column reads it: by completion, newest first. */
    private function doneQuery(Project $project): QueryBuilder
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.project = :project')
            ->andWhere('c.status = :status')
            ->setParameter('project', $project)
            ->setParameter('status', CardStatus::Done)
            ->orderBy('c.completedAt', 'DESC')
            ->addOrderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'DESC');
    }
}
